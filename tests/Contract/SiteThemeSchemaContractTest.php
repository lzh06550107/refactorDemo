<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/schema/v1/20260907_004_site_theme_runtime_up.sql';
$downPath = $root . '/database/schema/v1/20260907_004_site_theme_runtime_down.sql';
$repoPath = $root . '/modules/theme/infrastructure/ThinkPhpThemePublicationRepository.php';

expectTrue(is_file($upPath), 'R6 site/theme up migration must exist');
expectTrue(is_file($downPath), 'R6 site/theme down migration must exist');
expectTrue(is_file($repoPath), 'R6 transactional theme publication repository must exist');
$up = file_get_contents($upPath);
$down = file_get_contents($downPath);
$repo = file_get_contents($repoPath);
expectTrue(is_string($up) && is_string($down) && is_string($repo), 'R6 schema and repository sources must be readable');
foreach (['sites','domain_bindings','themes','theme_versions','style_instances','style_snapshots','site_theme_releases'] as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R6 migration creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R6 rollback drops ' . $table);
}
expectSame(7, substr_count($up, 'ENGINE=InnoDB'), 'all R6 tables use InnoDB');
expectSame(7, substr_count($up, 'DEFAULT CHARSET=utf8mb4'), 'all R6 tables use utf8mb4');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_domain_bindings_host` (`host`)'), 'host must be globally unambiguous');
expectTrue(str_contains($up, 'uk_sites_one_default_per_account'), 'one default site per account must be enforced');
expectTrue(
    str_contains(
        $up,
        '`default_account_id` varchar(64) GENERATED ALWAYS AS (CASE WHEN `is_default` = 1 THEN `account_id` ELSE NULL END) VIRTUAL',
    ),
    'default-site uniqueness must use a virtual generated column so account cascade remains valid on MySQL 8.4',
);
expectTrue(str_contains($up, 'uk_site_theme_release_idempotency'), 'theme publication idempotency must be database-enforced');
expectTrue(str_contains($repo, 'Db::transaction'), 'theme publication must be transactional');
expectTrue(str_contains($repo, '->lock(true)'), 'theme publication must lock the site row');
expectTrue(str_contains($repo, 'semanticIdempotencyMatches'), 'concurrent idempotent replay must compare semantic content rather than generated ids');
expectTrue(!str_contains($repo, 'eval('), 'theme publication repository must not execute template code');
