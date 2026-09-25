#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir -p "$TMP_DIR/bin"
cat > "$TMP_DIR/bin/curl" <<'PY'
#!/usr/bin/env python3
import json, os, pathlib, shutil, subprocess, sys
args=sys.argv[1:]; method='GET'; out=None; fmt=''; body=None; url=''
i=0
while i<len(args):
 a=args[i]
 if a in ('-X','-o','-w','--data-binary'):
  v=args[i+1]; i+=2
  if a=='-X': method=v
  elif a=='-o': out=v
  elif a=='-w': fmt=v
  else: body=v
 elif a in ('-H','--proto','--tlsv1.2','--connect-timeout','--max-time','--max-filesize'):
  i+=1 if a=='--tlsv1.2' else 2
 elif a.startswith('-'): i+=1
 else: url=a; i+=1
root=pathlib.Path(os.environ['TEST_STATE']); scenario=os.environ['TEST_SCENARIO']; statefile=root/'release.json'
with (root/'requests.log').open('a') as f: f.write(method+' '+url+'\n')
state=json.loads(statefile.read_text()) if statefile.exists() else None
status=200; data={}
if method=='GET' and '/assets/' in url:
 name=url.rsplit('/',1)[1]; src=root/name
 if out: shutil.copyfile(src,out)
 print(fmt.replace('%{http_code}','200').replace('%{redirect_url}','').replace('\\n','\n'),end=''); sys.exit(0)
if method=='GET':
 status=200 if state is not None else 404; data=state or {}
elif method=='POST' and url.endswith('/releases'):
 data=json.loads(pathlib.Path(body[1:]).read_text()); data.update(id=456,upload_url='https://uploads.github.com/repos/example/repo/releases/456/assets{?name,label}',assets=[]); state=data
 statefile.write_text(json.dumps(state)); status=201
 if scenario=='ambiguous-create': sys.exit(28)
elif method=='POST' and 'uploads.github.com' in url:
 if scenario in ('upload-fail','tag-moved','concurrent-publish'):
  if scenario=='tag-moved': subprocess.run(['git','--git-dir',os.environ['TEST_REMOTE'],'update-ref','refs/tags/v1.3.2',os.environ['TEST_COMMIT']],check=True)
  if scenario=='concurrent-publish': state['draft']=False; statefile.write_text(json.dumps(state))
  status=400
 else:
  name=url.split('name=',1)[1]; shutil.copyfile(body[1:],root/name)
  state['assets'].append({'name':name,'url':'https://api.github.com/assets/'+name}); statefile.write_text(json.dumps(state)); status=201
elif method=='PATCH':
 state['draft']=False; statefile.write_text(json.dumps(state)); data=state
 if scenario=='ambiguous-publish': sys.exit(28)
elif method=='DELETE':
 if state is None or str(state['id'])!=url.rsplit('/',1)[1]: sys.exit(1)
 statefile.unlink(); status=204
else: print('Unexpected fake request '+method+' '+url,file=sys.stderr); sys.exit(1)
if out: pathlib.Path(out).write_text(json.dumps(data))
print(fmt.replace('%{http_code}',str(status)).replace('%{redirect_url}','').replace('\\n','\n'),end='')
PY
chmod +x "$TMP_DIR/bin/curl"
export PATH="$TMP_DIR/bin:$PATH" GITHUB_TOKEN=fixture-token GITHUB_API_URL=https://api.github.com

for scenario in fresh existing-current existing-stale existing-draft existing-tag-mismatch existing-missing-tag upload-fail existing-tag-upload-fail ambiguous-create ambiguous-publish tag-moved concurrent-publish; do
  root="$TMP_DIR/$scenario"
  mkdir -p "$root/work" "$root/state"
  git init -q --bare "$root/remote.git"
  git -C "$root/work" init -q
  printf 'source\n' > "$root/work/source"
  git -C "$root/work" add source
  git -C "$root/work" -c user.name=Fixture -c user.email=fixture@example.invalid commit -qm fixture
  git -C "$root/work" config user.name Fixture
  git -C "$root/work" config user.email fixture@example.invalid
  git -C "$root/work" remote add origin "$root/remote.git"
  commit="$(git -C "$root/work" rev-parse HEAD)"
  export TEST_STATE="$root/state" TEST_REMOTE="$root/remote.git" TEST_COMMIT="$commit" TEST_SCENARIO="$scenario"
  if [ "$scenario" = existing-tag-upload-fail ]; then TEST_SCENARIO=upload-fail; export TEST_SCENARIO; fi
  printf 'artifact\n' > "$root/release.zip"
  printf 'checksum\n' > "$root/release.zip.sha256"
  printf 'signature\n' > "$root/release.zip.sha256.sig"
  printf 'Fixture release notes.\n' > "$root/notes.md"
  if [[ "$scenario" = existing-* ]]; then
    git -C "$root/work" tag -a v1.3.2 -m fixture
    git -C "$root/work" push -q origin refs/tags/v1.3.2
    old_tag="$(git --git-dir="$root/remote.git" rev-parse refs/tags/v1.3.2)"
  fi
  if [[ "$scenario" = existing-* ]] && [ "$scenario" != existing-tag-upload-fail ]; then
    for file in release.zip release.zip.sha256 release.zip.sha256.sig; do cp "$root/$file" "$root/state/$file"; done
    draft=false; [ "$scenario" != existing-draft ] || draft=true
    jq -n --argjson draft "$draft" '{id:123,tag_name:"v1.3.2",draft:$draft,name:"wp-core-base v1.3.2",body:"Fixture release notes.\n",assets:[{name:"release.zip",url:"https://api.github.com/assets/release.zip"},{name:"release.zip.sha256",url:"https://api.github.com/assets/release.zip.sha256"},{name:"release.zip.sha256.sig",url:"https://api.github.com/assets/release.zip.sha256.sig"}]}' > "$root/state/release.json"
    [ "$scenario" != existing-stale ] || printf stale > "$root/state/release.zip"
  fi
  if [ "$scenario" = existing-tag-mismatch ]; then
    git -C "$root/work" -c user.name=Fixture -c user.email=fixture@example.invalid commit --allow-empty -qm unrelated
    foreign="$(git -C "$root/work" rev-parse HEAD)"
    git -C "$root/work" push -q origin "$foreign:refs/heads/foreign"
    git --git-dir="$root/remote.git" update-ref refs/tags/v1.3.2 "$foreign"
  elif [ "$scenario" = existing-missing-tag ]; then
    git --git-dir="$root/remote.git" update-ref -d refs/tags/v1.3.2
  fi
  result=0
  (cd "$root/work" && bash "$SCRIPT_DIR/publish_framework_release.sh" example/repo v1.3.2 "$commit" "$root/notes.md" "$root/release.zip" "$root/release.zip.sha256" "$root/release.zip.sha256.sig" "$root/journal.json") > "$root/output" 2>&1 || result="$?"
  sed -n '/^{/,$p' "$root/output" > "$root/logged-journal.json"
  cmp "$root/journal.json" "$root/logged-journal.json"
  case "$scenario" in
    fresh)
      if [ "$result" != 0 ] || ! jq -e '.draft == false and .id == 456' "$root/state/release.json" >/dev/null; then cat "$root/output"; exit 1; fi
      jq -e '.published == true and .created_release_id == "456" and (.created_tag_object|length)==40' "$root/journal.json" >/dev/null
      ;;
    existing-current)
      [ "$result" = 0 ] || { cat "$root/output"; exit 1; }
      ! grep -Eq '^(DELETE|POST|PATCH) ' "$root/state/requests.log"
      ;;
    existing-stale|existing-draft)
      [ "$result" != 0 ] && [ -f "$root/state/release.json" ]
      ! grep -Eq '^(DELETE|POST|PATCH) ' "$root/state/requests.log"
      [ "$(git --git-dir="$root/remote.git" rev-parse refs/tags/v1.3.2)" = "$old_tag" ]
      ;;
    existing-tag-mismatch|existing-missing-tag)
      [ "$result" != 0 ] && [ -f "$root/state/release.json" ]
      if [ -f "$root/state/requests.log" ] && grep -Eq '^(DELETE|POST|PATCH) ' "$root/state/requests.log"; then
        echo 'Mismatched release identity caused a remote mutation.' >&2; exit 1
      fi
      if [ "$scenario" = existing-tag-mismatch ]; then
        [ "$(git --git-dir="$root/remote.git" rev-parse refs/tags/v1.3.2)" = "$foreign" ]
      else
        if git --git-dir="$root/remote.git" show-ref --verify --quiet refs/tags/v1.3.2; then
          echo 'An existing release without a tag must not recreate the tag.' >&2; exit 1
        fi
      fi
      ;;
    upload-fail)
      [ "$result" != 0 ] && [ -f "$root/state/release.json" ]
      git --git-dir="$root/remote.git" show-ref --verify --quiet refs/tags/v1.3.2
      ! grep -q '^DELETE ' "$root/state/requests.log"
      jq -e '.created_release_id == "456" and (.created_tag_object|length)==40' "$root/journal.json" >/dev/null
      export TEST_SCENARIO=fresh
      if (cd "$root/work" && bash "$SCRIPT_DIR/publish_framework_release.sh" example/repo v1.3.2 "$commit" "$root/notes.md" "$root/release.zip" "$root/release.zip.sha256" "$root/release.zip.sha256.sig" "$root/rerun-journal.json") > "$root/rerun-output" 2>&1; then
        echo 'A preserved draft must require explicit operator recovery.' >&2; exit 1
      fi
      jq -e '.draft == true and .id == 456' "$root/state/release.json" >/dev/null
      ;;
    existing-tag-upload-fail)
      [ "$result" != 0 ] && [ -f "$root/state/release.json" ]
      ! grep -q '^DELETE ' "$root/state/requests.log"
      [ "$(git --git-dir="$root/remote.git" rev-parse refs/tags/v1.3.2)" = "$old_tag" ]
      ;;
    concurrent-publish)
      [ "$result" != 0 ]
      jq -e '.draft == false and .id == 456' "$root/state/release.json" >/dev/null
      ! grep -q '^DELETE ' "$root/state/requests.log"
      git --git-dir="$root/remote.git" show-ref --verify --quiet refs/tags/v1.3.2
      ;;
    ambiguous-create|ambiguous-publish)
      [ "$result" != 0 ] && [ -f "$root/state/release.json" ]
      ! grep -q '^DELETE ' "$root/state/requests.log"
      git --git-dir="$root/remote.git" show-ref --verify --quiet refs/tags/v1.3.2
      jq -e '.ambiguous == true' "$root/journal.json" >/dev/null
      ;;
    tag-moved)
      [ "$result" != 0 ]
      [ "$(git --git-dir="$root/remote.git" rev-parse refs/tags/v1.3.2)" = "$commit" ]
      ;;
  esac
done
echo 'Release publication ownership and ambiguous-failure fixtures verified.'
