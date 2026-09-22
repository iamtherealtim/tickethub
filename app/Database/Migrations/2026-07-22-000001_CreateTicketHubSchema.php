<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTicketHubSchema extends Migration
{
    public function up()
    {
        $statements = [
            "CREATE TABLE business_hours (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                tz VARCHAR(60) NOT NULL DEFAULT 'UTC',
                days VARCHAR(40) NOT NULL DEFAULT 'Mon-Fri',
                time_range VARCHAR(40) NOT NULL DEFAULT '09:00 - 17:00',
                holidays VARCHAR(120) NOT NULL DEFAULT 'None'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE groups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                hours_id INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(150) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('Administrator','Supervisor','Agent','Requester') NOT NULL DEFAULT 'Requester',
                title VARCHAR(100) NULL,
                group_id INT UNSIGNED NULL,
                dept VARCHAR(60) NULL,
                site VARCHAR(60) NULL,
                phone VARCHAR(40) NULL,
                color VARCHAR(10) NOT NULL DEFAULT 'ink',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE tickets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(12) NOT NULL UNIQUE,
                subject VARCHAR(255) NOT NULL,
                requester_id INT UNSIGNED NOT NULL,
                agent_id INT UNSIGNED NULL,
                group_id INT UNSIGNED NOT NULL,
                status ENUM('New','Open','Pending','Resolved','Closed') NOT NULL DEFAULT 'New',
                priority ENUM('Urgent','High','Medium','Low') NOT NULL DEFAULT 'Medium',
                type ENUM('Incident','Service request') NOT NULL DEFAULT 'Incident',
                category VARCHAR(40) NOT NULL DEFAULT 'Software',
                source VARCHAR(20) NOT NULL DEFAULT 'Portal',
                site VARCHAR(60) NULL,
                tags TEXT NULL,
                escalated TINYINT(1) NOT NULL DEFAULT 0,
                csat_score TINYINT NULL,
                csat_comment TEXT NULL,
                fr_due DATETIME NOT NULL,
                res_due DATETIME NOT NULL,
                responded_at DATETIME NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_status (status),
                INDEX idx_agent (agent_id),
                INDEX idx_requester (requester_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE ticket_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT UNSIGNED NOT NULL,
                kind ENUM('description','reply','note','system') NOT NULL DEFAULT 'reply',
                user_id INT UNSIGNED NULL,
                body TEXT NOT NULL,
                attachments TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE ticket_tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                done TINYINT(1) NOT NULL DEFAULT 0,
                owner_id INT UNSIGNED NULL,
                INDEX idx_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE assets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tag VARCHAR(12) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'Laptop',
                model VARCHAR(100) NOT NULL DEFAULT '',
                serial VARCHAR(80) NOT NULL DEFAULT '',
                user_id INT UNSIGNED NULL,
                site VARCHAR(60) NOT NULL DEFAULT '',
                status VARCHAR(30) NOT NULL DEFAULT 'In use',
                warranty_until DATETIME NULL,
                os VARCHAR(60) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE ticket_assets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT UNSIGNED NOT NULL,
                asset_id INT UNSIGNED NOT NULL,
                UNIQUE KEY uq_ticket_asset (ticket_id, asset_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE articles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                category VARCHAR(60) NOT NULL,
                status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
                author_id INT UNSIGNED NOT NULL,
                body MEDIUMTEXT NOT NULL,
                tags TEXT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                up_votes INT UNSIGNED NOT NULL DEFAULT 0,
                down_votes INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE catalog_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                category VARCHAR(60) NOT NULL,
                icon VARCHAR(30) NOT NULL DEFAULT 'layers',
                description VARCHAR(255) NOT NULL DEFAULT '',
                sla VARCHAR(40) NOT NULL DEFAULT '',
                approval VARCHAR(40) NOT NULL DEFAULT 'None',
                fields TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE changes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(12) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                risk ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
                state ENUM('Awaiting approval','Scheduled','In progress','Completed','Rejected') NOT NULL DEFAULT 'Awaiting approval',
                type VARCHAR(20) NOT NULL DEFAULT 'Normal',
                owner_id INT UNSIGNED NOT NULL,
                window_at DATETIME NOT NULL,
                duration VARCHAR(12) NOT NULL DEFAULT '2h',
                impact VARCHAR(255) NOT NULL DEFAULT '',
                plan TEXT NULL,
                backout TEXT NULL,
                approvals TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE problems (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(12) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'Under investigation',
                priority ENUM('Urgent','High','Medium','Low') NOT NULL DEFAULT 'Medium',
                owner_id INT UNSIGNED NOT NULL,
                linked INT UNSIGNED NOT NULL DEFAULT 0,
                opened_at DATETIME NOT NULL,
                cause TEXT NULL,
                workaround TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE announcements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                level ENUM('info','warn','alert') NOT NULL DEFAULT 'info',
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE slas (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                first_response VARCHAR(40) NOT NULL DEFAULT '1 hour',
                resolution VARCHAR(40) NOT NULL DEFAULT '8 hours',
                hours VARCHAR(40) NOT NULL DEFAULT 'Business hours',
                escalation VARCHAR(120) NOT NULL DEFAULT 'None',
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE automations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                when_event VARCHAR(60) NOT NULL,
                cond VARCHAR(255) NOT NULL DEFAULT '',
                action VARCHAR(255) NOT NULL DEFAULT '',
                runs INT UNSIGNED NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE ticket_fields (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(80) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'Text',
                required TINYINT(1) NOT NULL DEFAULT 0,
                agents TINYINT(1) NOT NULL DEFAULT 1,
                portal TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE email_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                trigger_event VARCHAR(60) NOT NULL,
                recipient VARCHAR(30) NOT NULL DEFAULT 'Requester',
                subject VARCHAR(150) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE canned_responses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(80) NOT NULL,
                body TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($statements as $sql) {
            $this->db->query($sql);
        }
    }

    public function down()
    {
        $tables = [
            'canned_responses', 'email_templates', 'ticket_fields', 'automations', 'slas',
            'announcements', 'problems', 'changes', 'catalog_items', 'articles',
            'ticket_assets', 'assets', 'ticket_tasks', 'ticket_messages', 'tickets',
            'users', 'groups', 'business_hours',
        ];
        foreach ($tables as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
