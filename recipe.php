<?php

namespace Deployer;

require 'recipe/common.php';

set('current_path', '{{deploy_path}}');
set('sudo', 'sudo --user www-data');
set('bin/git', 'git');
set('dcea', ''); // Empty if no docker or something like "docker compose exec -T apache" otherwise
set('bin/sh', '{{dcea}} {{sudo}} sh');
set('bin/php', '{{dcea}} {{sudo}} php');
set('bin/composer', '{{dcea}} {{sudo}} composer');
set('composer_options', '--no-interaction --no-progress --no-scripts');
set('bin/console', '{{dcea}} {{sudo}} php bin/console'); // Or "app/console"
set('console_options', '--env=prod');
set('bin/mysqldump', '{{dcea}} {{sudo}} mysqldump');

// Recipe tasks
task('deploy:git:pull', function () {
    $output = run('cd {{deploy_path}} && {{bin/git}} pull');
    // writeln($output);
    done('Pull done!');
})->verbose();
task('deploy:cache:clear', function () {
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
    info('Database dumped !');
    run('gzip -f {{alias}}.db.sql');
    info('Database zipped !');
    download('{{deploy_path}}/{{alias}}.db.sql.gz', 'database/{{alias}}.db.sql.gz');
    info('Database downloaded !');
    run('rm {{alias}}.db.sql.gz');
});
task('database:load', function () {
    runLocally('gzip -dk database/{{alias}}.db.sql.gz');
    runLocally('{{LOCAL_MYSQL}} < database/{{alias}}.db.sql');
    runLocally('rm database/{{alias}}.db.sql');
    info('Database loaded !');
});

// Other tasks
task('log', function() {
    run('cd {{deploy_path}} && {{bin/git}} log -10 --oneline');
})->verbose();
task('status', function() {
    run('cd {{deploy_path}} && {{bin/git}} status --branch --porcelain');
})->verbose();

// Task functions
function done($message) {
    writeln('<info>✔</info> ' . $message);
}
