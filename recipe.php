<?php

namespace Deployer;

require 'recipe/common.php';

option('origin', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'When you need a host as an origin. Ex: copy from origin to destination.', $default = null);
option('light', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'When you need to run a  light version of a command.');

set('current_path', '{{deploy_path}}');
set('sudo', 'sudo --user www-data');
set('bin/git', 'git');
set('dc', ''); // Empty if no docker or something like "docker compose exec -T apache" otherwise
set('dcea', ''); // Empty if no docker or something like "docker compose exec -T apache" otherwise
set('dcem', '{{dcea}} {{sudo}}');
set('bin/sh', '{{dcea}} {{sudo}} sh');
set('bin/php', '{{dcea}} {{sudo}} php');
set('bin/composer', '{{dcea}} {{sudo}} composer');
set('composer_options', '--no-interaction --no-progress --no-scripts');
set('bin/console', '{{bin/php}} bin/console'); // Or "app/console"
set('console_options', '--env=prod');
set('bin/mysqldump', '{{dcea}} {{sudo}} mysqldump');
set('bin/mysql', '{{dcem}} mysql');

// Recipe tasks
task('deploy:git:pull', function () {
    runv('cd {{deploy_path}} && {{bin/git}} pull');
    done('Pull done!');
});
task('deploy:cache:clear', function () {
    run('cd {{deploy_path}} && {{bin/console}} cache:clear {{console_options}}');
    done('Cache clear done!');
});
task('deploy:cache:clear:no-warmup', function () {
    run('cd {{deploy_path}} && {{bin/console}} cache:clear --no-warmup {{console_options}}');
    done('Cache clear done!');
});
task('deploy:cache:warmup', function () {
    run('cd {{deploy_path}} && {{bin/console}} cache:warmup {{console_options}}');
    done('Cache warmup done!');
});
task('deploy:schema:update', function () {
    run('cd {{deploy_path}} && {{bin/console}} doctrine:schema:update --force --dump-sql');
    done('Schema update done!');
});
task('deploy:database:migrate', function () {
    run('cd {{deploy_path}} && {{bin/console}} doctrine:migration:migrate -n');
    done('Migration done!');
});
task('deploy:assets:install', function () {
    run('cd {{deploy_path}} && {{bin/console}} assets:install --symlink');
    done('Assets install done!');
});
task('deploy:npm:install', function () {
    run('cd {{deploy_path}} && {{bin/sh}} -c ". nodeenv/bin/activate; node -v; npm -v; npm install"');
    done('NPM install done!');
});
task('deploy:npm:build', function () {
    run('cd {{deploy_path}} && {{bin/sh}} -c ". nodeenv/bin/activate; npm run build"');
    done('NPM build done!');
});
task('deploy:success', function () {
    done('Deploy successful!');
    echo chr(7);
});

// Project tasks (Usually overriden)
task('deploy', ['deploy:git:pull']);
task('deploy:fast', ['deploy:git:pull']);

after('deploy', 'deploy:success');
after('deploy:fast', 'deploy:success');




// Database tasks
task('database:update', ['database:get', 'database:load']);
task('database:get', function () {
    cd('{{deploy_path}}');
    run('{{bin/mysqldump}} --opt --single-transaction --no-autocommit -Q --compress --result-file={{alias}}.db.sql -h {{db_host}} -u {{db_user}} -p{{db_password}} {{db_name}}');
    info('Database dumped!');
    run('gzip -f {{alias}}.db.sql');
    info('Database zipped!');
    download('{{deploy_path}}/{{alias}}.db.sql.gz', 'database/{{alias}}.db.sql.gz');
    info('Database downloaded!');
    run('rm {{alias}}.db.sql.gz');
});
task('database:get:docker', function () {
    cd('{{deploy_path}}');
    run('{{bin/mysql}} sh -c \'export MYSQL_PWD=$MYSQL_PASSWORD ; mysqldump --opt --single-transaction --no-autocommit -Q --compress -u $MYSQL_USER $MYSQL_DATABASE\' | gzip -c > /tmp/{{alias}}.db.sql.gz');
    download('/tmp/{{alias}}.db.sql.gz', 'database/{{alias}}.db.sql.gz');
    run('rm /tmp/{{alias}}.db.sql.gz');
    done('Database downloaded!');
});
task('database:send', function () {
    $alias = input()->getOption('origin');
    if ($alias === null) {
        $alias = 'prod';
        warning("No origin host was specified, <options=bold>$alias</> was used by default. To specify the host, use the --origin option.");
    }
    upload(__DIR__."/../database/$alias.db.sql.gz", "{{deploy_path}}database/$alias.db.sql.gz");
    done('Database uploaded!');
});
task('database:load', function () {
    runLocally('gzip -dk database/{{alias}}.db.sql.gz');
    runLocally('{{LOCAL_MYSQL}} < database/{{alias}}.db.sql');
    runLocally('rm database/{{alias}}.db.sql');
    info('Database loaded!');
});
task('database:access', function () {
    cd('{{deploy_path}}');
    run('{{bin/mysql}} -h {{db_host}} -u {{db_user}} -p{{db_password}} -e "use {{db_name}}"');
    done('Database access granted!');
});
task('database:mysql', function () {
    command('{{bin/mysql}} -h {{db_host}} -u {{db_user}} -p{{db_password}} -e "use {{db_name}}; %command%;"', 'mysql');
});
task('database:mysql:show', function () {
    cd('{{deploy_path}}');
    runv('{{bin/mysql}} -h {{db_host}} -u {{db_user}} -p{{db_password}} -e "use {{db_name}}; show tables;"');
});



// Other tasks
task('log', function() {
    run('cd {{deploy_path}} && {{bin/git}} log -10 --oneline');
})->verbose();
task('status', function() {
    run('cd {{deploy_path}} && {{bin/git}} status --branch --porcelain');
})->verbose();
task('command', function () {
    command('{{dcea}} {{sudo}} %command%');
});
task('git:download-modified-files', function () {
    cd('{{deploy_path}}');
    $modifiedFiles = explode(PHP_EOL,run('git diff --name-status | cut -f2'));
    foreach ($modifiedFiles as $modifiedFile) {
        download('{{deploy_path}}'.$modifiedFile, $modifiedFile);
    }
});
task('git:checkout', function () {
    cd('{{deploy_path}}');
    run('git fetch --all');
    $allBranches = explode(PHP_EOL, run('git branch -a'));
    $allBranches = array_map(function($branchName) { return substr(str_replace('remotes/origin/','', $branchName), 2); }, $allBranches);
    $branch = ask('Quelle branche utiliser ? (autocompletion activée)', null, $allBranches);
    if (!$branch) {
        writeln('<error>Merci de spécifier la branche</error>');
        error();
    }
    run('git checkout '.$branch);
});

// Recipe update
task('update', function () {
    runLocally('cd deployer && curl -o recipe.php "https://raw.githubusercontent.com/sigmapix/deployer/master/recipe.php"');
});



// Task functions
function done($message) {
    writeln('<info>✔</info> ' . $message);
}
function runv($command) {
    $result = run($command);
    writeln($result);
}
function command($pattern, $logfile = 'history') {
    $command = ask('Which command to execute?');

    $historyFilename = __DIR__ . '/' . $logfile . '.log';
    $history = file_exists($historyFilename) ? explode(PHP_EOL, file_get_contents($historyFilename)) : [];
    if ($command == '' && $history) {
        $command = askChoice('Select from history', $history);
    }
    if ($command) {
        $confirm = askConfirmation(sprintf('Do you confirm this command : "%s" ?', $command));
        if ($confirm) {
            cd('{{deploy_path}}');
            runv(str_replace('%command%', $command, $pattern));
            done('Command successfully executed!');

            $history[] = $command;
            file_put_contents($historyFilename, implode(PHP_EOL, array_unique($history)));
        }
    } else {
        writeln('No command to execute!');
    }
}

// Needs to be reviewed by everyone
function loadSqlFileInMysqlDockerContainer($remotePath)
{
    $user = '$MYSQL_USER';
    $isGzipped = strpos($remotePath, '.gz') != false;
    $cat = $isGzipped ? 'zcat' : 'cat';
    $fileNameInContainer = $isGzipped ? 'db.sql.gz' : 'db.sql';
    run('{{dc}} run --rm -v '.$remotePath.':/tmp/'.$fileNameInContainer.' mysql sh -c \'export MYSQL_PWD=$MYSQL_PASSWORD ; '.$cat.' /tmp/'.$fileNameInContainer.' | mysql -u '.$user.' -h mysql $MYSQL_DATABASE\'', [], 3600);
}


task('database:loadnew', function () {
    list($exportsAsArray, $exportsAsString) = generateImportEnvStatement('.env.local');
    $databases = listDatabaseDumpFiles();
    if (empty($databases[0])) {
        writeln('<error>No databases found</error>');
        exit(1);
    } elseif (count($databases) == 1) {
        $fileNameInContainer = $databases[0];
    } else {
        $fileNameInContainer = askChoice('What file would you like to load?', $databases);
    }
    info($fileNameInContainer);
    $dropSchema = '';
    if (askConfirmation('Would you like to drop the schema before?')) {
        $dropSchema = 'DROP SCHEMA IF EXISTS '.$exportsAsArray['MYSQL_DATABASE'].'; ';
    }
    run( 'docker exec -i '.$exportsAsArray['MYSQL_HOST'].' sh -c \''.$exportsAsString.' export MYSQL_PWD=$MYSQL_PASSWORD ; mysql -u $MYSQL_USER -h $MYSQL_HOST --execute="'.$dropSchema.'CREATE SCHEMA IF NOT EXISTS '.$exportsAsArray['MYSQL_DATABASE'].' ;"\'', [], 3600);
    run( 'zcat < '.$fileNameInContainer.' | docker exec -i '.$exportsAsArray['MYSQL_HOST'].' sh -c \''.$exportsAsString.' export MYSQL_PWD=$MYSQL_PASSWORD ; mysql -u $MYSQL_USER -h $MYSQL_HOST $MYSQL_DATABASE\'', [], 3600);
})->select('LoadDBAllowed=ok');

function generateImportEnvStatement($envFilePath):array {
    $envFileContent = run('cat {{deploy_path}}/'.$envFilePath);
    $envs = parse_ini_string($envFileContent, false, INI_SCANNER_RAW);
    return [$envs, implode("", array_map(function ($k, $v) { return "export $k=$v; "; }, array_keys($envs), array_values($envs)))];
};
function listDatabaseDumpFiles()
{
    run('mkdir -p {{deploy_path}}/database'); // creates directory if not exists
    cd('{{deploy_path}}');
    return explode(PHP_EOL, run('find database -type f \( -name "*.sql" -o -name "*.sql.gz" \)'));
}
