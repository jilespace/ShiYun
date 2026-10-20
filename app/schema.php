<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/**
 * 数据库结构定义。改结构时把 SCHEMA_VERSION 加一，下次请求会自动补建。
 */
const SCHEMA_VERSION = 15;

/** @return list<string> */
function schema_statements(): array
{
    $sql = [];

    // ------------------------------------------------------------------ 账户
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email           VARCHAR(190)    NOT NULL,
            username        VARCHAR(64)     NOT NULL,
            password_hash   VARCHAR(255)    NOT NULL,
            display_name    VARCHAR(64)     NOT NULL,
            avatar          VARCHAR(255)    NULL,
            bio             VARCHAR(255)    NULL,
            role            ENUM('user','admin')            NOT NULL DEFAULT 'user',
            status          ENUM('active','disabled')       NOT NULL DEFAULT 'active',
            quota_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            used_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            root_id         BIGINT UNSIGNED NULL,
            trash_root_id   BIGINT UNSIGNED NULL,
            private_root_id BIGINT UNSIGNED NULL,
            private_hash    VARCHAR(255)    NULL,
            totp_secret     VARCHAR(64)     NULL,
            totp_enabled    TINYINT(1)      NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL,
            updated_at      DATETIME        NOT NULL,
            last_login_at   DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_users_email (email),
            UNIQUE KEY uk_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS user_settings (
            user_id               BIGINT UNSIGNED NOT NULL,
            view_mode             ENUM('list','grid')  NOT NULL DEFAULT 'list',
            sort_key              VARCHAR(16) NOT NULL DEFAULT 'name',
            sort_dir              ENUM('asc','desc')   NOT NULL DEFAULT 'asc',
            show_hidden           TINYINT(1)  NOT NULL DEFAULT 0,
            trash_retention_days  INT         NOT NULL DEFAULT 30,
            upload_speed_limit    INT         NOT NULL DEFAULT 0,
            download_speed_limit  INT         NOT NULL DEFAULT 0,
            default_share_perm    ENUM('preview','download','upload') NOT NULL DEFAULT 'download',
            notify_login          TINYINT(1)  NOT NULL DEFAULT 1,
            upload_source_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            prefs                 JSON        NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS login_logs (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NULL,
            email      VARCHAR(190)    NOT NULL,
            ip         VARCHAR(45)     NOT NULL,
            ua         VARCHAR(255)    NULL,
            device     VARCHAR(64)     NULL,
            status     ENUM('success','fail','locked') NOT NULL,
            reason     VARCHAR(120)    NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_login_user (user_id, created_at),
            KEY idx_login_email (email, created_at),
            KEY idx_login_ip (ip, created_at),
            KEY idx_login_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS password_resets (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64)        NOT NULL,
            expires_at DATETIME        NOT NULL,
            used_at    DATETIME        NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_reset_token (token_hash),
            KEY idx_reset_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    // TOTP 一次性恢复码：只存哈希，用掉即标记，认证器丢失时自救
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS totp_recovery_codes (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            code_hash  CHAR(64)        NOT NULL,
            used_at    DATETIME        NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_trc_hash (code_hash),
            KEY idx_trc_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS api_tokens (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(64)     NOT NULL,
            token_hash   CHAR(64)        NOT NULL,
            scopes       VARCHAR(255)    NOT NULL DEFAULT '*',
            last_used_at DATETIME        NULL,
            expires_at   DATETIME        NULL,
            created_at   DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_api_token (token_hash),
            KEY idx_api_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    // -------------------------------------------------------------- 存储底层
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS blobs (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hash       CHAR(64)        NOT NULL,
            size       BIGINT UNSIGNED NOT NULL,
            mime       VARCHAR(128)    NULL,
            ref_count  INT UNSIGNED    NOT NULL DEFAULT 0,
            -- 照片指纹与拍摄信息：缩略图生成时顺手算出，用于相似照片与按拍摄时间归档
            phash      BIGINT          NULL,
            taken_at   DATETIME        NULL,
            camera     VARCHAR(120)    NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_blob_hash (hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS storage_sources (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(64)     NOT NULL,
            driver     ENUM('local','s3','ftp','sftp') NOT NULL DEFAULT 'local',
            config     JSON            NULL,
            root_path  VARCHAR(512)    NULL,
            is_default TINYINT(1)      NOT NULL DEFAULT 0,
            priority   INT             NOT NULL DEFAULT 0,
            status     ENUM('ok','error') NOT NULL DEFAULT 'ok',
            used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_src_user (user_id, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS blob_locations (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blob_id    BIGINT UNSIGNED NOT NULL,
            source_id  BIGINT UNSIGNED NOT NULL,
            object_key VARCHAR(512)    NOT NULL,
            size       BIGINT UNSIGNED NOT NULL,
            status     ENUM('pending','ok','failed','stale') NOT NULL DEFAULT 'pending',
            error      VARCHAR(500)    NULL,
            created_at DATETIME        NOT NULL,
            updated_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_blob_source (blob_id, source_id),
            KEY idx_blobloc_status (status),
            KEY idx_blobloc_key (source_id, object_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    // ------------------------------------------------------------ 文件与目录
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS nodes (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            parent_id     BIGINT UNSIGNED NULL,
            name          VARCHAR(255)    NOT NULL,
            name_key      VARCHAR(255)    NOT NULL,
            kind          ENUM('file','folder') NOT NULL,
            size          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mime          VARCHAR(128)    NULL,
            ext           VARCHAR(32)     NULL,
            blob_id       BIGINT UNSIGNED NULL,
            path          VARCHAR(1024)   NOT NULL DEFAULT '',
            depth         INT             NOT NULL DEFAULT 0,
            is_favorite   TINYINT(1)      NOT NULL DEFAULT 0,
            is_hidden     TINYINT(1)      NOT NULL DEFAULT 0,
            is_locked     TINYINT(1)      NOT NULL DEFAULT 0,
            is_private    TINYINT(1)      NOT NULL DEFAULT 0,
            color         VARCHAR(16)     NULL,
            note          TEXT            NULL,
            lock_hash     VARCHAR(255)    NULL,
            child_count   INT             NOT NULL DEFAULT 0,
            deleted_at    DATETIME        NULL,
            deleted_from  BIGINT UNSIGNED NULL,
            created_at    DATETIME        NOT NULL,
            updated_at    DATETIME        NOT NULL,
            -- 最近一次被预览/下载的时间，用来识别长期没动过的冷数据
            last_access_at DATETIME       NULL,
            PRIMARY KEY (id),
            KEY idx_node_parent (user_id, parent_id, deleted_at),
            KEY idx_node_path (user_id, path(255)),
            KEY idx_node_fav (user_id, is_favorite, deleted_at),
            KEY idx_node_name (user_id, name_key),
            KEY idx_node_ext (user_id, ext),
            -- 按创建时间倒序的列表与收藏；没有这个索引会退化成 filesort（实测慢约 18 倍）
            KEY idx_node_created (user_id, created_at),
            KEY idx_node_trash (user_id, deleted_at),
            KEY idx_node_blob (blob_id),
            KEY idx_node_access (user_id, last_access_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS tags (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(48)     NOT NULL,
            color      VARCHAR(16)     NOT NULL DEFAULT '#3d3aff',
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tag_user (user_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS node_tags (
            node_id BIGINT UNSIGNED NOT NULL,
            tag_id  BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (node_id, tag_id),
            KEY idx_nt_tag (tag_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS node_versions (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            node_id    BIGINT UNSIGNED NOT NULL,
            version_no INT             NOT NULL,
            blob_id    BIGINT UNSIGNED NOT NULL,
            size       BIGINT UNSIGNED NOT NULL,
            mime       VARCHAR(128)    NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_ver_node (node_id, version_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS file_index (
            node_id      BIGINT UNSIGNED NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL,
            mime         VARCHAR(128)    NULL,
            width        INT             NULL,
            height       INT             NULL,
            duration     INT             NULL,
            pages        INT             NULL,
            text_content MEDIUMTEXT      NULL,
            indexed_at   DATETIME        NOT NULL,
            PRIMARY KEY (node_id),
            KEY idx_fi_user (user_id),
            FULLTEXT KEY ft_file_text (text_content) WITH PARSER ngram
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    // ------------------------------------------------------------------ 分享
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS shares (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id        BIGINT UNSIGNED NOT NULL,
            node_id        BIGINT UNSIGNED NOT NULL,
            token          CHAR(24)        NOT NULL,
            password_hash  VARCHAR(255)    NULL,
            permission     ENUM('preview','download','upload','inbox') NOT NULL DEFAULT 'download',
            expires_at     DATETIME        NULL,
            max_downloads  INT             NULL,
            download_count INT             NOT NULL DEFAULT 0,
            view_count     INT             NOT NULL DEFAULT 0,
            -- 收取箱：给对方看的说明、最多收几个文件、已收几个
            inbox_note     VARCHAR(500)    NULL,
            max_files      INT             NULL,
            upload_count   INT             NOT NULL DEFAULT 0,
            status         ENUM('active','revoked') NOT NULL DEFAULT 'active',
            created_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_share_token (token),
            KEY idx_share_user (user_id, created_at),
            KEY idx_share_node (node_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS share_visits (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            share_id   BIGINT UNSIGNED NOT NULL,
            ip         VARCHAR(45)     NULL,
            ua         VARCHAR(255)    NULL,
            action     ENUM('view','download','upload') NOT NULL,
            node_id    BIGINT UNSIGNED NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_sv_share (share_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS folder_members (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            node_id    BIGINT UNSIGNED NOT NULL,
            owner_id   BIGINT UNSIGNED NOT NULL,
            member_id  BIGINT UNSIGNED NOT NULL,
            role       ENUM('viewer','uploader','editor') NOT NULL DEFAULT 'viewer',
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_fm (node_id, member_id),
            KEY idx_fm_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    // ------------------------------------------------------------ 传输与任务
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS upload_sessions (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id        BIGINT UNSIGNED NOT NULL,
            upload_id      CHAR(32)        NOT NULL,
            filename       VARCHAR(255)    NOT NULL,
            relative_path  VARCHAR(512)    NULL,
            size           BIGINT UNSIGNED NOT NULL,
            chunk_size     INT UNSIGNED    NOT NULL,
            total_chunks   INT UNSIGNED    NOT NULL,
            received       JSON            NOT NULL,
            received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            parent_id      BIGINT UNSIGNED NULL,
            source_id      BIGINT UNSIGNED NULL,
            -- 非空表示这是公开分享（收取箱 / 可上传）的分片会话，不绑定登录用户
            share_id       BIGINT UNSIGNED NULL,
            hash           CHAR(64)        NULL,
            status         ENUM('pending','uploading','merging','done','failed','canceled') NOT NULL DEFAULT 'pending',
            node_id        BIGINT UNSIGNED NULL,
            created_at     DATETIME        NOT NULL,
            updated_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_upload_id (upload_id),
            KEY idx_us_user (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS transfer_tasks (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            kind        ENUM('offline','bt','archive','copy','move','download','push','purge') NOT NULL,
            title       VARCHAR(255)    NOT NULL,
            payload     JSON            NOT NULL,
            progress    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status      ENUM('queued','running','paused','done','failed','canceled') NOT NULL DEFAULT 'queued',
            error       VARCHAR(500)    NULL,
            retries     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            priority    TINYINT         NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL,
            started_at  DATETIME        NULL,
            finished_at DATETIME        NULL,
            PRIMARY KEY (id),
            KEY idx_task_user (user_id, status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    // ------------------------------------------------------------ 日志与统计
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS activities (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            node_id    BIGINT UNSIGNED NULL,
            action     VARCHAR(48)     NOT NULL,
            detail     VARCHAR(500)    NULL,
            ip         VARCHAR(45)     NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_act_user (user_id, created_at),
            KEY idx_act_node (node_id, created_at),
            -- 定时任务按 created_at 清理过期日志；没有这个索引就得全表扫
            KEY idx_act_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS traffic_stats (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id          BIGINT UNSIGNED NOT NULL,
            day              DATE            NOT NULL,
            uploaded_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            downloaded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            upload_count     INT UNSIGNED    NOT NULL DEFAULT 0,
            download_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_traffic_day (user_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS storage_snapshots (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            day          DATE            NOT NULL,
            used_bytes   BIGINT UNSIGNED NOT NULL,
            file_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            folder_count INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_snap_day (user_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS automation_rules (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(64)     NOT NULL,
            trigger_type ENUM('upload','schedule','manual') NOT NULL DEFAULT 'upload',
            conditions   JSON            NULL,
            actions      JSON            NOT NULL,
            enabled      TINYINT(1)      NOT NULL DEFAULT 1,
            last_run_at  DATETIME        NULL,
            run_count    INT UNSIGNED    NOT NULL DEFAULT 0,
            created_at   DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_rule_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS direct_links (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id        BIGINT UNSIGNED NOT NULL,
            node_id        BIGINT UNSIGNED NOT NULL,
            token          CHAR(32)        NOT NULL,
            ip_allow       VARCHAR(64)     NULL,
            expires_at     DATETIME        NULL,
            status         ENUM('active','revoked') NOT NULL DEFAULT 'active',
            hit_count      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_access_at DATETIME        NULL,
            created_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_direct_token (token),
            KEY idx_direct_user (user_id, created_at),
            KEY idx_direct_node (node_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    return $sql;
}
