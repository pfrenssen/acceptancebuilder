<?php

/**
 * @file
 * Deploys an acceptance environment through a web interface.
 */

use Composer\Console\Application;
use GitWrapper\GitWrapper;
use Symfony\Component\Console\Input\ArrayInput;

require_once 'vendor/autoload.php';

echo '<h1>Acceptance builder</h1>';

// Load the configuration.
$config = json_decode(file_get_contents('acceptance.json'));
if (empty($config)) {
  echo '<p>Error: configuration file not found.</p>';
  exit(1);
}

$wrapper = new GitWrapper();

// Set SSH key if defined.
if (!empty($config->ssh_private_key)) {
  $wrapper->setPrivateKey($config->ssh_private_key);
}

$canonical_repo = $wrapper->workingCopy($config->canonical_repo);

// Update the repo and display the form if it hasn't been submitted.
if (empty($_POST['branch'])) {
  // Fetch latest changes.
  $canonical_repo->fetch('origin');

  // Get the list of remote repositories and filter them.
  $filter = $config->branch_filter;
  $branches = array_filter($canonical_repo->getBranches()->remote(), function($value) use ($filter) {
    return strpos($value, $filter) !== FALSE;
  });

  if (!empty($branches)) {
    $options = [];
    foreach ($branches as $branch) {
      $branch = htmlspecialchars($branch);
      $label = substr($branch, 7);
      $options[] = '<option value="' . $branch . '">' . $label . '</option>';
    }
    $options = implode(PHP_EOL, $options);
    echo <<<HTML
<form action="index.php" method="post">
  <select name="branch">
$options
  </select>
  <input type="submit" />
</form>
HTML;
  }
  else {
    echo 'No suitable branches found to deploy.';
  }
}
else {
  // Turn off output buffering, these operations might take some time.
  ob_end_flush();

  // Validate that the branch exists on the remote.
  $branch = $_POST['branch'];
  $branches = $canonical_repo->getBranches()->remote();
  if (!in_array($branch, $branches)) {
    echo 'Error: branch "' . htmlspecialchars($branch) . '" does not exist.';
    exit(1);
  }

  $local_branch = substr($branch, 7);
  $path = $config->repo_dir . '/' . strtolower($local_branch);

  // Check out the branch on the canonical repo first to ensure that it exists
  // locally.
  $canonical_repo->checkout($local_branch);
  $canonical_repo->reset($branch, ['hard' => TRUE]);

  // Check if an existing copy of the website exists.
  if (file_exists($path)) {
    // Fetch and reset --hard.
    $repo = $wrapper->workingCopy($path);
    $repo->fetch('origin');
    $repo->reset($branch, ['hard' => TRUE]);
  }
  else {
    // Clone the repo.
    $repo = $wrapper->clone($config->canonical_repo, $path);
    $repo->checkout($local_branch);
  }
  // Echo the output from the operation.
  echo('<p>' . (string) $repo . '</p>');

  // Run composer install.
  echo '<p>Running composer install. Please be patient.</p>';
  flush();

  putenv('COMPOSER_HOME=' . __DIR__ . '/vendor/bin/composer');

  $arguments = [
    'command' => 'install',
    '--working-dir' => $path,
  ];
  $input = new ArrayInput($arguments);
  $application = new Application();
  $application->setAutoExit(FALSE);
  $return = $application->run($input);

  if ($return) {
    echo '<p>Error: composer install failed.</p>';
    exit(1);
  }

  // Run Phing targets.
  if (!empty($config->phing_targets)) {
    echo '<p>Running Phing targets "' . implode('", "', $config->phing_targets) . '". Please be patient.</p>';
    flush();

    $phing_bin = $path . '/vendor/bin/phing';
    $phing_buildfile = $path . '/build.xml';

    // Build an array of Phing properties, replacing dynamic values from a
    // whitelist.
    $database_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $local_branch));
    $phing_whitelist = [
      '${database_name}' => $database_name,
      '${database_user}' => $config->database_user,
      '${database_password}' => $config->database_password,
    ];

    $phing_properties = [];
    foreach ($config->phing_properties as $property => $value) {
      if (array_key_exists($value, $phing_whitelist)) {
        $value = $phing_whitelist[$value];
      }
      $phing_properties[] = '-D' . $property . '=' . $value;
    }
    $args = array_merge(
      [
        $path . '/vendor/bin/phing',
        '-buildfile',
        $path . '/build.xml',
      ],
      $phing_properties,
      $config->phing_targets
    );
    $command = escapeshellcmd(implode(' ', $args)) . ' 2>&1';
    $output = $return = NULL;
    exec($command, $output, $return);
    echo (implode('<br>', $output));

    if ($return) {
      echo '<p>Error: execution of Phing targets failed.</p>';
      exit(1);
    }
  }

  // Create symlink.
  $target = $path . '/' . $config->build_dir;
  $link = $config->web_root . '/' . strtolower($local_branch);
  if (!file_exists($link)) {
    symlink($target, $link);
  }

  echo '<p><a href="' . strtolower($local_branch) . '">Build complete</a>.</p>';
}
