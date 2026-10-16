<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Store;

use IdempotentImporter\Wp\Options;
use wpdb;

/** The plugin's own tables. Created and upgraded by dbDelta; dropped only on uninstall. */
final class Tables
{
    public const SCHEMA_VERSION = 1;
    public const VERSION_OPTION = 'iim_schema_version';

    public const LINKS = 'iim_links';
    public const REDIRECTS = 'iim_redirects';
    public const SEEN = 'iim_seen';
    public const RUNS = 'iim_runs';

    public function __construct(private readonly wpdb $db) {}

    public function name(string $table): string
    {
        return $this->db->prefix . $table;
    }

    public function install(): void
    {
        if (Options::int(self::VERSION_OPTION) === self::SCHEMA_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->name(self::LINKS)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(32) NOT NULL,
  gid varchar(191) NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  hash char(64) NOT NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_gid (kind,gid),
  KEY kind_target (kind,target_id)
) {$charset};");
        dbDelta("CREATE TABLE {$this->name(self::REDIRECTS)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path varchar(191) NOT NULL,
  kind varchar(32) NOT NULL,
  gid varchar(191) NOT NULL,
  target_url text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path (path),
  KEY kind_gid (kind,gid)
) {$charset};");
        dbDelta("CREATE TABLE {$this->name(self::SEEN)} (
  run_id varchar(36) NOT NULL,
  kind varchar(32) NOT NULL,
  gid varchar(191) NOT NULL,
  PRIMARY KEY  (run_id,kind,gid)
) {$charset};");
        dbDelta("CREATE TABLE {$this->name(self::RUNS)} (
  id varchar(36) NOT NULL,
  mode varchar(16) NOT NULL,
  dry_run tinyint(1) NOT NULL,
  status varchar(16) NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  counts longtext NOT NULL,
  message text NULL,
  PRIMARY KEY  (id),
  KEY started_at (started_at)
) {$charset};");
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    public function uninstall(): void
    {
        foreach ([self::LINKS, self::REDIRECTS, self::SEEN, self::RUNS] as $table) {
            Sql::execute($this->db, 'DROP TABLE IF EXISTS %i', $this->name($table));
        }
        delete_option(self::VERSION_OPTION);
    }
}
