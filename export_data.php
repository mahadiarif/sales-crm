<?php

$tables = [
    'users',
    'roles',
    'user_roles',
    'privileges',
    'privilege_role',
    'services',
    'service_packages',
    'leads',
    'visits',
    'sales',
    'follow_ups',
    'pipeline_stages'
];

$data = [];

foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        $data[$table] = DB::table($table)->get();
        echo "Exported: $table (" . count($data[$table]) . " rows)\n";
    } else {
        echo "Skipped: $table (Table not found)\n";
    }
}

file_put_contents('database_dump.json', json_encode($data));
echo "Done! database_dump.json updated with RBAC tables.\n";
