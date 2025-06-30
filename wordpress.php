<?php

namespace Deployer;

task('wordpress:database:get', function () {
    cd('{{deploy_path}}');
    run('wp db export {{alias}}.db.sql --add-drop-table --exclude_tables=wp_redirection_404');
    info('Database dumped!');
    run('gzip -f {{alias}}.db.sql');
    info('Database zipped!');
    download('{{deploy_path}}/{{alias}}.db.sql.gz', 'database/{{alias}}.db.sql.gz');
    info('Wordpress Database downloaded!');
    run('rm {{alias}}.db.sql.gz');
});
