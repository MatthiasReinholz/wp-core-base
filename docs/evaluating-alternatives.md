# Evaluating wp-core-base Against Alternatives

`wp-core-base` is an application composition and maintenance framework: it declares runtime ownership, prepares reviewed dependency changes, versions its own tooling, and stages deployment input. Evaluate alternatives against the downstream problem the team needs to solve.

## Identify the Comparator

“Woo Release” is not an unambiguous repository or version identifier. A defensible comparison needs the exact repository URL, commit or release, supported use case, and installation/configuration used in the experiment. Until those are recorded, do not claim feature, security, speed, or maintenance superiority over that project.

If the intended comparator primarily builds and publishes a plugin's release archive, it addresses the publisher's workflow. `wp-core-base` can consume an archive produced by such tooling when it satisfies a supported source contract. That may make the tools complementary. Confirm the actual comparator's responsibilities before classifying it.

One public reference is WooCommerce's [release process](https://developer.woocommerce.com/docs/contribution/releases/), which covers building, publishing, release readiness, monitoring and security support. Its [automation overview](https://developer.woocommerce.com/2025/08/14/how-ai-and-automation-are-improving-the-woo-release-process/) describes GitHub-driven builds and publication. These sources establish a publisher workflow; they do not identify a separate application foundation named `woo-release`. If that is the intended comparison, evaluate publishing and recovery practices separately from downstream ownership, dependency ingestion and runtime assembly. A shared use of GitHub Actions is not evidence that either project is faster or more reliable.

## Compare Complete Workflows

Use the same WordPress application and dependency versions, with the same runner resources, network/cache conditions, permissions, and validation requirements. Exercise both `full-core` and `content-only` when both matter to the team.

| Evaluation question | wp-core-base evidence to inspect | Evidence required from the alternative |
| --- | --- | --- |
| Can custom code remain project-owned? | Managed/local/ignored manifest rules and ownership regression tests. | Equivalent overwrite boundaries and failure behavior. |
| Can the team review reproducible dependency changes? | Archive ingestion, checksums, component identities, PR scope, queueing and reconciliation. | Full update-to-review workflow with the same dependencies. |
| Can deployment consume a controlled payload? | Staged runtime validation for both repository profiles. | An equivalent deployment artifact and handling of undeclared paths. |
| Are trust decisions explicit? | Source/asset identity, signed framework checksums, optional dependency sidecars, credential-origin restrictions. | Authentication boundaries, provenance verification and defaults. |
| Does interruption preserve recoverability? | Staging, dependency transaction and Git lease failure tests; retained recovery evidence. | The same injected failures and concurrent changes. |
| Can the team maintain the tool itself? | Versioned framework lock, scaffold checksums, installation compatibility and migration notes. | Version pinning, update and customization behavior. |
| What is the operational cost? | Measured archive/extraction costs, update completion time, review effort and CI usage. | The same measurements under equivalent conditions. |

Also record unsupported dependencies, premium-provider implementation effort, onboarding time, documentation errors, and manual recovery steps. More configuration or features can add maintenance cost; assess whether the application actually needs them.

## Measuring Package Cost

From the framework source repository, compare two local, already verified artifacts:

```bash
php scripts/ci/benchmark_framework_artifacts.php --baseline=/path/to/old.zip --candidate=/path/to/new.zip --iterations=3
```

The JSON result records each artifact's SHA-256, archive bytes, entry count, uncompressed bytes, individual validated extraction durations, and median extraction duration, plus PHP/OS/architecture. It uses fresh private output directories. The iteration count may be 1–10.

This measures local packaging and validated extraction cost. Filesystem caches are uncontrolled. It does not measure network throughput, complete downstream installation, application request throughput, review time, or CI minutes. Measure those separately before claiming a gain in them. A comparison between the old full snapshot and the smaller tooling ZIP establishes an internal distribution improvement; it is not a performance comparison with Woo Release.

Keep the raw results, exact source revisions, runner specifications, and validation outcomes with the evaluation. Repeat the experiment after changing a material input. For download cost, report bytes separately from elapsed network time; for CI cost, compare complete jobs with equivalent checks.

## Positioning That the Evidence Supports

The framework is a strong candidate when a team needs explicit ownership of a mixed WordPress runtime, reviewed updates from supported archive sources, controlled staging, and a versioned framework maintenance path. The smaller tooling artifact avoids duplicating the optional starter runtime into every vendored installation.

Choose a simpler alternative when those governance and composition requirements are unnecessary, or when its supported sources and deployment model fit the application better. A claim that wp-core-base is more efficient or more reliable should name the workload, comparator revision, measured result, and remaining limitation. “More sophisticated” is useful only when the added capability reduces a real operational burden.

See [evaluation-guide.md](evaluation-guide.md), [support-matrix.md](support-matrix.md), [security-model.md](security-model.md), and [operations.md](operations.md).
