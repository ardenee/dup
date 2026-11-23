<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fresh DUP v2 schema: InnoDB-ready modular profiles, immutable scan runs, indexed files, jobs and audit';
    }

    private function withId(Table $table): Table
    {
        $table->addColumn('id', Types::INTEGER, ['autoincrement'=>true]);
        $table->setPrimaryKey(['id']);
        $table->addOption('engine', 'InnoDB');
        return $table;
    }

    public function up(Schema $unused): void
    {
        $schema = new Schema();

        $users = $this->withId($schema->createTable('users'));
        $users->addColumn('username', Types::STRING, ['length'=>64]);
        $users->addColumn('password_hash', Types::STRING, ['length'=>255]);
        $users->addColumn('roles', Types::TEXT);
        $users->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $users->addUniqueIndex(['username']);

        $types = $this->withId($schema->createTable('profile_types'));
        $types->addColumn('name', Types::STRING, ['length'=>100]);
        $types->addColumn('extensions', Types::TEXT);
        $types->addUniqueIndex(['name']);

        $profiles = $this->withId($schema->createTable('scan_profiles'));
        $profiles->addColumn('name', Types::STRING, ['length'=>140]);
        $profiles->addColumn('source_root', Types::TEXT);
        $profiles->addColumn('move_root', Types::TEXT);
        $profiles->addColumn('profile_type_id', Types::INTEGER);
        $profiles->addColumn('minimum_bytes', Types::BIGINT, ['default'=>'0']);
        $profiles->addColumn('hash_mode', Types::STRING, ['length'=>16]);
        $profiles->addColumn('enabled', Types::BOOLEAN, ['default'=>true]);
        $profiles->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $profiles->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $profiles->addIndex(['profile_type_id']);
        $profiles->addForeignKeyConstraint('profile_types',['profile_type_id'],['id']);

        $ignore = $this->withId($schema->createTable('profile_ignore_rules'));
        $ignore->addColumn('profile_id', Types::INTEGER, ['notnull'=>false]);
        $ignore->addColumn('folder_name', Types::STRING, ['length'=>255]);
        $ignore->addColumn('enabled', Types::BOOLEAN, ['default'=>true]);
        $ignore->addIndex(['profile_id']);
        $ignore->addForeignKeyConstraint('scan_profiles',['profile_id'],['id'],['onDelete'=>'CASCADE']);

        $runs = $this->withId($schema->createTable('scan_runs'));
        $runs->addColumn('profile_id', Types::INTEGER);
        $runs->addColumn('state', Types::STRING, ['length'=>24]);
        $runs->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull'=>false]);
        $runs->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull'=>false]);
        $runs->addColumn('files_seen', Types::BIGINT, ['default'=>'0']);
        $runs->addColumn('files_indexed', Types::BIGINT, ['default'=>'0']);
        $runs->addColumn('bytes_indexed', Types::BIGINT, ['default'=>'0']);
        $runs->addColumn('duplicate_groups', Types::INTEGER, ['default'=>0]);
        $runs->addColumn('error_count', Types::INTEGER, ['default'=>0]);
        $runs->addIndex(['profile_id','state']);
        $runs->addForeignKeyConstraint('scan_profiles',['profile_id'],['id']);

        $directories = $this->withId($schema->createTable('directories'));
        $directories->addColumn('run_id', Types::INTEGER);
        $directories->addColumn('parent_id', Types::INTEGER, ['notnull'=>false]);
        $directories->addColumn('path', Types::TEXT);
        $directories->addColumn('path_hash', Types::STRING, ['length'=>64]);
        $directories->addColumn('basename', Types::STRING, ['length'=>255]);
        $directories->addColumn('is_ignored', Types::BOOLEAN, ['default'=>false]);
        $directories->addUniqueIndex(['run_id','path_hash']);
        $directories->addIndex(['run_id','parent_id']);
        $directories->addForeignKeyConstraint('scan_runs',['run_id'],['id'],['onDelete'=>'CASCADE']);

        $files = $this->withId($schema->createTable('file_entries'));
        $files->addColumn('run_id', Types::INTEGER);
        $files->addColumn('directory_id', Types::INTEGER);
        $files->addColumn('filename', Types::STRING, ['length'=>255]);
        $files->addColumn('path_hash', Types::STRING, ['length'=>64]);
        $files->addColumn('size_bytes', Types::BIGINT);
        $files->addColumn('mtime_epoch', Types::BIGINT);
        $files->addColumn('hash_method', Types::STRING, ['length'=>16,'notnull'=>false]);
        $files->addColumn('hash_value', Types::STRING, ['length'=>128,'notnull'=>false]);
        $files->addColumn('is_symlink', Types::BOOLEAN, ['default'=>false]);
        $files->addColumn('error_code', Types::STRING, ['length'=>64,'notnull'=>false]);
        $files->addUniqueIndex(['run_id','path_hash']);
        $files->addIndex(['run_id','size_bytes']);
        $files->addIndex(['run_id','size_bytes','hash_value']);
        $files->addIndex(['directory_id']);
        $files->addForeignKeyConstraint('scan_runs',['run_id'],['id'],['onDelete'=>'CASCADE']);
        $files->addForeignKeyConstraint('directories',['directory_id'],['id']);

        $groups = $this->withId($schema->createTable('duplicate_groups'));
        $groups->addColumn('run_id', Types::INTEGER);
        $groups->addColumn('group_key', Types::STRING, ['length'=>128]);
        $groups->addColumn('size_bytes', Types::BIGINT);
        $groups->addColumn('file_count', Types::INTEGER);
        $groups->addColumn('reclaimable_bytes', Types::BIGINT);
        $groups->addColumn('rules_version', Types::INTEGER, ['default'=>1]);
        $groups->addIndex(['run_id','size_bytes']);
        $groups->addForeignKeyConstraint('scan_runs',['run_id'],['id'],['onDelete'=>'CASCADE']);

        $members = $this->withId($schema->createTable('duplicate_group_members'));
        $members->addColumn('group_id', Types::INTEGER);
        $members->addColumn('file_entry_id', Types::INTEGER);
        $members->addUniqueIndex(['group_id','file_entry_id']);
        $members->addForeignKeyConstraint('duplicate_groups',['group_id'],['id'],['onDelete'=>'CASCADE']);
        $members->addForeignKeyConstraint('file_entries',['file_entry_id'],['id']);

        $jobs = $this->withId($schema->createTable('jobs'));
        $jobs->addColumn('kind', Types::STRING, ['length'=>40]);
        $jobs->addColumn('profile_id', Types::INTEGER, ['notnull'=>false]);
        $jobs->addColumn('run_id', Types::INTEGER, ['notnull'=>false]);
        $jobs->addColumn('state', Types::STRING, ['length'=>24]);
        $jobs->addColumn('phase', Types::STRING, ['length'=>40]);
        $jobs->addColumn('progress_current', Types::BIGINT, ['default'=>'0']);
        $jobs->addColumn('progress_total', Types::BIGINT, ['notnull'=>false]);
        $jobs->addColumn('heartbeat_at', Types::DATETIME_IMMUTABLE, ['notnull'=>false]);
        $jobs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $jobs->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull'=>false]);
        $jobs->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull'=>false]);
        $jobs->addColumn('error_code', Types::STRING, ['length'=>100,'notnull'=>false]);
        $jobs->addColumn('attempts', Types::INTEGER, ['default'=>0]);
        $jobs->addColumn('worker_token', Types::STRING, ['length'=>64,'notnull'=>false]);
        $jobs->addIndex(['state','created_at']);
        $jobs->addIndex(['state','heartbeat_at']);
        $jobs->addIndex(['profile_id']);
        $jobs->addForeignKeyConstraint('scan_profiles',['profile_id'],['id']);
        $jobs->addForeignKeyConstraint('scan_runs',['run_id'],['id']);

        $events = $this->withId($schema->createTable('job_events'));
        $events->addColumn('job_id', Types::INTEGER);
        $events->addColumn('severity', Types::STRING, ['length'=>12]);
        $events->addColumn('code', Types::STRING, ['length'=>80]);
        $events->addColumn('context', Types::TEXT);
        $events->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $events->addIndex(['job_id','created_at']);
        $events->addForeignKeyConstraint('jobs',['job_id'],['id'],['onDelete'=>'CASCADE']);

        $batches = $this->withId($schema->createTable('file_action_batches'));
        $batches->addColumn('actor_id', Types::INTEGER);
        $batches->addColumn('profile_id', Types::INTEGER);
        $batches->addColumn('action_kind', Types::STRING, ['length'=>24]);
        $batches->addColumn('state', Types::STRING, ['length'=>24]);
        $batches->addColumn('requested_at', Types::DATETIME_IMMUTABLE);
        $batches->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull'=>false]);
        $batches->addForeignKeyConstraint('users',['actor_id'],['id']);
        $batches->addForeignKeyConstraint('scan_profiles',['profile_id'],['id']);

        $items = $this->withId($schema->createTable('file_action_items'));
        $items->addColumn('batch_id', Types::INTEGER);
        $items->addColumn('file_entry_id', Types::INTEGER);
        $items->addColumn('source_path', Types::TEXT);
        $items->addColumn('target_path', Types::TEXT, ['notnull'=>false]);
        $items->addColumn('state', Types::STRING, ['length'=>24]);
        $items->addColumn('result_code', Types::STRING, ['length'=>100,'notnull'=>false]);
        $items->addIndex(['batch_id','state']);
        $items->addForeignKeyConstraint('file_action_batches',['batch_id'],['id'],['onDelete'=>'CASCADE']);
        $items->addForeignKeyConstraint('file_entries',['file_entry_id'],['id']);

        $audit = $this->withId($schema->createTable('audit_events'));
        $audit->addColumn('actor_id', Types::INTEGER, ['notnull'=>false]);
        $audit->addColumn('action', Types::STRING, ['length'=>100]);
        $audit->addColumn('entity_type', Types::STRING, ['length'=>80]);
        $audit->addColumn('entity_id', Types::STRING, ['length'=>80,'notnull'=>false]);
        $audit->addColumn('correlation_id', Types::STRING, ['length'=>64]);
        $audit->addColumn('outcome', Types::STRING, ['length'=>40]);
        $audit->addColumn('context', Types::TEXT);
        $audit->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $audit->addIndex(['created_at']);
        $audit->addIndex(['correlation_id']);
        $audit->addForeignKeyConstraint('users',['actor_id'],['id']);

        foreach($schema->toSql($this->connection->getDatabasePlatform()) as $sql)
            $this->addSql($sql);

        foreach([
            ['Books','mobi,prc'],['Comics','cbr,cbz'],
            ['Documents','pdf,rtf,doc,docx'],
            ['Videos','avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,vob'],
            ['Games','iso,zip,rar,exe,msi,nds'],
            ['Music','wma,mpc,mp3,flac,wav,ogg'],
            ['Pictures','bmp,png,gif,jpg,jpeg'],
            ['Archives','zip,rar,7z,tar,gz'],
            ['All File Types','']
        ] as [$name,$extensions]) {
            $this->addSql('INSERT INTO profile_types (name,extensions) VALUES (?,?)',
                [$name,$extensions]);
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Dropping DUP v2 data is not an automatic rollback operation.');
    }
}
