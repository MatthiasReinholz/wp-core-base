<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface GitHubAutomationClient extends AutomationClient, GitHubPullRequestReader
{
}
