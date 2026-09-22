<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class TicketHubSeeder extends Seeder
{
    private function ago(float $h): string
    {
        return date('Y-m-d H:i:s', time() - (int) round($h * 3600));
    }

    private function ahead(float $h): string
    {
        return date('Y-m-d H:i:s', time() + (int) round($h * 3600));
    }

    public function run()
    {
        // Demo data (shared password, fictional people, sample tickets) has no
        // business on a live desk. Explicit opt-in only.
        if (ENVIRONMENT === 'production' && (string) getenv('TICKETHUB_ALLOW_DEMO_SEED') !== '1') {
            throw new \RuntimeException(
                'TicketHubSeeder refuses to run with CI_ENVIRONMENT=production. '
                . 'Use "php spark tickethub:setup" to create the first administrator, '
                . 'or set TICKETHUB_ALLOW_DEMO_SEED=1 if you really want demo data.'
            );
        }

        $db  = $this->db;
        $now = date('Y-m-d H:i:s');
        $pw  = password_hash('password', PASSWORD_DEFAULT);

        $db->table('business_hours')->insertBatch([
            ['name' => 'Canada business hours', 'tz' => 'America/Toronto', 'days' => 'Mon-Fri', 'time_range' => '08:00 - 18:00', 'holidays' => 'Canadian statutory'],
            ['name' => 'Follow the sun', 'tz' => 'UTC', 'days' => 'Mon-Sun', 'time_range' => '00:00 - 24:00', 'holidays' => 'None'],
        ]);

        $db->table('groups')->insertBatch([
            ['name' => 'Service Desk', 'description' => 'First line triage and support', 'hours_id' => 1],
            ['name' => 'Network & Infra', 'description' => 'Connectivity, servers, datacentre', 'hours_id' => 2],
            ['name' => 'Endpoint Engineering', 'description' => 'Laptops, mobile, imaging', 'hours_id' => 1],
            ['name' => 'Identity & Access', 'description' => 'Accounts, SSO, permissions', 'hours_id' => 1],
            ['name' => 'Applications', 'description' => 'Business systems and integrations', 'hours_id' => 1],
        ]);

        // 1-6 agents, 7-14 requesters. Everyone must replace the shared demo
        // password at first sign-in (users.must_change_password, see AuthHardening).
        $userDefaults = ['group_id' => null, 'dept' => null, 'site' => null, 'phone' => null, 'must_change_password' => 1];
        $userRows = [
            ['name' => 'Maya Ortiz', 'email' => 'maya.ortiz@tickethub.co', 'password_hash' => $pw, 'role' => 'Administrator', 'title' => 'Service Desk Lead', 'group_id' => 1, 'color' => 'brand', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Devin Park', 'email' => 'devin.park@tickethub.co', 'password_hash' => $pw, 'role' => 'Agent', 'title' => 'Support Analyst', 'group_id' => 1, 'color' => 'violet', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Priya Raman', 'email' => 'priya.raman@tickethub.co', 'password_hash' => $pw, 'role' => 'Agent', 'title' => 'Network Engineer', 'group_id' => 2, 'color' => 'signal', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Tom Baird', 'email' => 'tom.baird@tickethub.co', 'password_hash' => $pw, 'role' => 'Agent', 'title' => 'Endpoint Engineer', 'group_id' => 3, 'color' => 'ink', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Aisha Bello', 'email' => 'aisha.bello@tickethub.co', 'password_hash' => $pw, 'role' => 'Agent', 'title' => 'IAM Specialist', 'group_id' => 4, 'color' => 'alert', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Luis Ferreira', 'email' => 'luis.ferreira@tickethub.co', 'password_hash' => $pw, 'role' => 'Supervisor', 'title' => 'Applications Analyst', 'group_id' => 5, 'color' => 'brand', 'active' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Jordan Whitfield', 'email' => 'jordan.whitfield@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Financial Analyst', 'dept' => 'Finance', 'site' => 'Toronto HQ', 'phone' => '+1 416 555 0148', 'color' => 'ink', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Camille Roy', 'email' => 'camille.roy@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Brand Manager', 'dept' => 'Marketing', 'site' => 'Montréal', 'phone' => '+1 514 555 0192', 'color' => 'violet', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Ben Osei', 'email' => 'ben.osei@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Account Executive', 'dept' => 'Sales', 'site' => 'Remote', 'phone' => '+1 647 555 0110', 'color' => 'signal', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Hana Suzuki', 'email' => 'hana.suzuki@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Staff Engineer', 'dept' => 'Engineering', 'site' => 'Toronto HQ', 'phone' => '+1 416 555 0177', 'color' => 'brand', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Marcus Doyle', 'email' => 'marcus.doyle@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Warehouse Lead', 'dept' => 'Operations', 'site' => 'Mississauga', 'phone' => '+1 905 555 0133', 'color' => 'alert', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Elena Petrova', 'email' => 'elena.petrova@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'HR Partner', 'dept' => 'People', 'site' => 'Toronto HQ', 'phone' => '+1 416 555 0165', 'color' => 'ink', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Sam Nguyen', 'email' => 'sam.nguyen@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Counsel', 'dept' => 'Legal', 'site' => 'Vancouver', 'phone' => '+1 604 555 0121', 'color' => 'violet', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Rafi Haddad', 'email' => 'rafi.haddad@tickethub.co', 'password_hash' => $pw, 'role' => 'Requester', 'title' => 'Facilities Coord.', 'dept' => 'Facilities', 'site' => 'Mississauga', 'phone' => '+1 905 555 0154', 'color' => 'signal', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ];
        foreach ($userRows as $row) {
            $db->table('users')->insert($row + $userDefaults);
        }

        $db->table('assets')->insertBatch([
            ['tag' => 'AST-0912', 'name' => 'MBP-14 / JW-0912', 'type' => 'Laptop', 'model' => 'MacBook Pro 14" M3', 'serial' => 'C02XK1PLQ6NY', 'user_id' => 7, 'site' => 'Toronto HQ', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 280), 'os' => 'macOS 15.2'],
            ['tag' => 'AST-0741', 'name' => 'DELL-7440 / CR', 'type' => 'Laptop', 'model' => 'Dell Latitude 7440', 'serial' => '8XK2P41', 'user_id' => 8, 'site' => 'Montréal', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 60), 'os' => 'Windows 11 23H2'],
            ['tag' => 'AST-1180', 'name' => 'iPhone 15 / BO', 'type' => 'Mobile', 'model' => 'iPhone 15 128GB', 'serial' => 'F9GYT2KQ1M', 'user_id' => 9, 'site' => 'Remote', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 140), 'os' => 'iOS 18.3'],
            ['tag' => 'AST-0334', 'name' => 'TOR-SW-CORE-02', 'type' => 'Switch', 'model' => 'Cisco C9300-48P', 'serial' => 'FOC2317L0KZ', 'user_id' => null, 'site' => 'Toronto HQ', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 400), 'os' => 'IOS-XE 17.9'],
            ['tag' => 'AST-0556', 'name' => 'TOR-VPN-01', 'type' => 'Server', 'model' => 'Dell PowerEdge R650', 'serial' => 'JH8821Q', 'user_id' => null, 'site' => 'Toronto HQ', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 95), 'os' => 'Ubuntu 22.04'],
            ['tag' => 'AST-0620', 'name' => 'MISS-PRN-FLOOR2', 'type' => 'Printer', 'model' => 'HP LaserJet E60155', 'serial' => 'CNB7K21094', 'user_id' => null, 'site' => 'Mississauga', 'status' => 'Needs repair', 'warranty_until' => $this->ago(24 * 40), 'os' => '—'],
            ['tag' => 'AST-0455', 'name' => 'MBP-16 / HS', 'type' => 'Laptop', 'model' => 'MacBook Pro 16" M2', 'serial' => 'C02ZL9PMTY1', 'user_id' => 10, 'site' => 'Toronto HQ', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 30), 'os' => 'macOS 15.1'],
            ['tag' => 'AST-0198', 'name' => 'Dock-TB4 spare #12', 'type' => 'Dock', 'model' => 'CalDigit TS4', 'serial' => 'TS4-88120', 'user_id' => null, 'site' => 'Toronto HQ', 'status' => 'In stock', 'warranty_until' => $this->ahead(24 * 200), 'os' => '—'],
            ['tag' => 'AST-0877', 'name' => 'LAT-5540 / MD', 'type' => 'Laptop', 'model' => 'Dell Latitude 5540', 'serial' => '2JK9V71', 'user_id' => 11, 'site' => 'Mississauga', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 12), 'os' => 'Windows 11 23H2'],
            ['tag' => 'AST-0301', 'name' => 'Adobe CC — 40 seats', 'type' => 'License', 'model' => 'Creative Cloud Team', 'serial' => 'LIC-ADB-40', 'user_id' => null, 'site' => '—', 'status' => 'In use', 'warranty_until' => $this->ahead(24 * 88), 'os' => '—'],
        ]);

        $db->table('articles')->insertBatch([
            ['title' => 'Reset your TicketHub password', 'category' => 'Accounts & access', 'status' => 'Published', 'author_id' => 5, 'views' => 2841, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['password', 'sso']), 'created_at' => $this->ago(600), 'updated_at' => $this->ago(72),
                'body' => '<p>You can reset your own password at any time from the identity portal — no ticket needed. The change applies to email, VPN, and every app behind single sign-on within about a minute.</p><h2>Reset it yourself</h2><ul><li>Go to <code>id.tickethub.co/reset</code> on any device.</li><li>Enter your work email and approve the push notification on your phone.</li><li>Choose a new passphrase of at least 14 characters.</li></ul><h2>If the push never arrives</h2><p>Your enrolled device may have changed. Open a ticket with the Service Desk and an analyst will re-enrol you after an identity check. Bring photo ID if you are on site.</p><h2>Password rules</h2><ul><li>14 characters minimum, no expiry</li><li>Cannot reuse your last 5 passphrases</li><li>Blocked if it appears in a known breach list</li></ul>'],
            ['title' => 'Connect to VPN from a personal network', 'category' => 'Network', 'status' => 'Published', 'author_id' => 3, 'views' => 1930, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['vpn', 'remote']), 'created_at' => $this->ago(700), 'updated_at' => $this->ago(30),
                'body' => '<p>The company VPN uses certificate-based authentication. The certificate installs automatically the first time you connect from a managed laptop.</p><h2>First connection</h2><ul><li>Open the VPN client from your applications folder.</li><li>Select the gateway closest to you — <code>tor-vpn-01</code> for Canada, <code>ams-vpn-02</code> for Europe.</li><li>Approve the MFA prompt.</li></ul><h2>Common errors</h2><p><code>Error 812</code> means your certificate has expired. Reconnect on the office network once, or raise a ticket and we will push a fresh certificate.</p><p>Slow throughput on home Wi-Fi is usually a 2.4GHz band issue. Move to the 5GHz SSID before opening a ticket.</p>'],
            ['title' => 'Request software that is not in the catalog', 'category' => 'Software', 'status' => 'Published', 'author_id' => 6, 'views' => 864, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['software', 'procurement']), 'created_at' => $this->ago(900), 'updated_at' => $this->ago(190),
                'body' => '<p>Anything not listed in the service catalog needs a security and licensing review before it can be installed. Plan for three to five business days.</p><h2>What to include in your request</h2><ul><li>Vendor name and product page</li><li>What the tool does that catalog software cannot</li><li>Whether it stores customer or employee data</li><li>Your cost centre for the licence</li></ul><h2>What happens next</h2><p>Applications reviews the request, Security reviews data handling, and your manager approves the spend. You will see each approval on the ticket as it clears.</p>'],
            ['title' => 'Set up a new starter on day one', 'category' => 'Onboarding', 'status' => 'Published', 'author_id' => 1, 'views' => 1204, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['onboarding', 'hr']), 'created_at' => $this->ago(500), 'updated_at' => $this->ago(14),
                'body' => '<p>Hiring managers raise the onboarding request at least five working days before the start date so hardware can be imaged and shipped.</p><h2>What we provision</h2><ul><li>Laptop, dock, and one external monitor</li><li>Email, calendar, and directory entry</li><li>Access to the apps assigned to the person\'s role profile</li></ul><h2>Day one</h2><p>The starter collects their laptop from the Service Desk on floor 3, or receives it by courier if remote. Sign-in uses the temporary passphrase sent to the hiring manager.</p>'],
            ['title' => 'Printing from a personal device', 'category' => 'Hardware', 'status' => 'Published', 'author_id' => 4, 'views' => 512, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['printing']), 'created_at' => $this->ago(1000), 'updated_at' => $this->ago(240),
                'body' => '<p>Personal devices cannot print directly to office printers. Use secure release printing instead.</p><h2>How it works</h2><ul><li>Email your document to <code>print@tickethub.co</code> from your work address.</li><li>Tap your badge on any printer within four hours.</li><li>Select the job and press release.</li></ul><p>Jobs older than four hours are deleted automatically.</p>'],
            ['title' => 'Recognising a phishing email', 'category' => 'Security', 'status' => 'Published', 'author_id' => 5, 'views' => 1670, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['security', 'phishing']), 'created_at' => $this->ago(800), 'updated_at' => $this->ago(56),
                'body' => '<p>Report anything suspicious with the Report Phish button in Outlook. Reporting a legitimate message costs nothing; ignoring a real one can cost a great deal.</p><h2>Signals worth a second look</h2><ul><li>Urgency about money, payroll, or gift cards</li><li>A reply-to address that differs from the sender</li><li>Links that resolve to a domain you do not recognise</li><li>A first-time sender asking you to break a process</li></ul><h2>If you already clicked</h2><p>Disconnect from the network and call the Service Desk on extension 4400. Speed matters more than tidiness here.</p>'],
            ['title' => 'Meeting room AV troubleshooting', 'category' => 'Hardware', 'status' => 'Draft', 'author_id' => 4, 'views' => 0, 'up_votes' => 0, 'down_votes' => 0, 'tags' => json_encode(['av', 'rooms']), 'created_at' => $this->ago(6), 'updated_at' => $this->ago(6),
                'body' => '<p>Draft — pending review by Facilities.</p><h2>No display on the room screen</h2><ul><li>Wake the panel with the touch controller before connecting.</li><li>Use the USB-C cable at the table, not the HDMI spur.</li></ul>'],
        ]);

        $db->table('catalog_items')->insertBatch([
            ['name' => 'New laptop', 'category' => 'Hardware', 'icon' => 'laptop', 'description' => 'Standard or engineering spec, delivered in 3 business days.', 'sla' => '3 business days', 'approval' => 'Manager', 'fields' => json_encode([['k' => 'spec', 'label' => 'Specification', 'type' => 'select', 'opts' => ['Standard — 16GB', 'Engineering — 32GB', 'Design — 32GB + GPU']], ['k' => 'reason', 'label' => 'Why do you need it?', 'type' => 'textarea']])],
            ['name' => 'Software licence', 'category' => 'Software', 'icon' => 'layers', 'description' => 'Request a seat for catalog-approved software.', 'sla' => '2 business days', 'approval' => 'Manager', 'fields' => json_encode([['k' => 'app', 'label' => 'Application', 'type' => 'select', 'opts' => ['Adobe Creative Cloud', 'Figma', 'Tableau', 'JetBrains', 'Miro']], ['k' => 'cc', 'label' => 'Cost centre', 'type' => 'text']])],
            ['name' => 'Application access', 'category' => 'Accounts & access', 'icon' => 'lock', 'description' => 'Add or change your permissions in a business system.', 'sla' => '1 business day', 'approval' => 'System owner', 'fields' => json_encode([['k' => 'sys', 'label' => 'System', 'type' => 'select', 'opts' => ['NetSuite', 'Salesforce', 'Workday', 'Snowflake', 'Jira']], ['k' => 'level', 'label' => 'Access level', 'type' => 'select', 'opts' => ['Read only', 'Contributor', 'Approver']]])],
            ['name' => 'Onboard a new starter', 'category' => 'Onboarding', 'icon' => 'users', 'description' => 'Hardware, accounts, and app access for a new hire.', 'sla' => '5 business days', 'approval' => 'None', 'fields' => json_encode([['k' => 'starter', 'label' => 'Starter name', 'type' => 'text'], ['k' => 'start', 'label' => 'Start date', 'type' => 'date'], ['k' => 'role', 'label' => 'Role profile', 'type' => 'select', 'opts' => ['Sales', 'Engineering', 'Finance', 'Marketing', 'Operations']]])],
            ['name' => 'Offboard an employee', 'category' => 'Onboarding', 'icon' => 'logout', 'description' => 'Revoke access and recover equipment on the last day.', 'sla' => '2 business days', 'approval' => 'People team', 'fields' => json_encode([['k' => 'who', 'label' => 'Employee name', 'type' => 'text'], ['k' => 'last', 'label' => 'Last working day', 'type' => 'date']])],
            ['name' => 'Mobile phone and plan', 'category' => 'Hardware', 'icon' => 'phone', 'description' => 'Company mobile with a Canadian data plan.', 'sla' => '4 business days', 'approval' => 'Manager', 'fields' => json_encode([['k' => 'device', 'label' => 'Device', 'type' => 'select', 'opts' => ['iPhone 15', 'iPhone 15 Pro', 'Pixel 9']], ['k' => 'travel', 'label' => 'International roaming needed?', 'type' => 'select', 'opts' => ['No', 'Yes — occasional', 'Yes — frequent']]])],
            ['name' => 'Meeting room AV setup', 'category' => 'Facilities', 'icon' => 'monitor', 'description' => 'Book a technician to prepare a room for an event.', 'sla' => 'Same day', 'approval' => 'None', 'fields' => json_encode([['k' => 'room', 'label' => 'Room', 'type' => 'select', 'opts' => ['Ontario — 3F', 'Superior — 3F', 'Huron — 2F', 'Erie — 2F']], ['k' => 'when', 'label' => 'Date needed', 'type' => 'date']])],
            ['name' => 'Guest Wi-Fi voucher', 'category' => 'Network', 'icon' => 'zap', 'description' => 'Time-limited network access for visitors.', 'sla' => '2 hours', 'approval' => 'None', 'fields' => json_encode([['k' => 'guest', 'label' => 'Guest or company name', 'type' => 'text'], ['k' => 'days', 'label' => 'How many days?', 'type' => 'select', 'opts' => ['1', '3', '7', '30']]])],
        ]);

        $db->table('changes')->insertBatch([
            ['code' => 'CHG-0311', 'title' => 'Firmware upgrade — Toronto core switches', 'risk' => 'High', 'state' => 'Awaiting approval', 'type' => 'Normal', 'owner_id' => 3, 'window_at' => $this->ahead(52), 'duration' => '4h', 'impact' => 'Toronto HQ, all floors', 'approvals' => json_encode([['by' => 1, 'status' => 'Approved'], ['by' => 6, 'status' => 'Pending']]), 'plan' => 'Upgrade C9300 pair to IOS-XE 17.12 in sequence, failover verified between units.', 'backout' => 'Roll back to 17.9 image held on flash; 20 minute restore per unit.'],
            ['code' => 'CHG-0309', 'title' => 'Quarterly patch cycle — Windows fleet', 'risk' => 'Medium', 'state' => 'Scheduled', 'type' => 'Standard', 'owner_id' => 4, 'window_at' => $this->ahead(96), 'duration' => '6h', 'impact' => '412 Windows endpoints', 'approvals' => json_encode([['by' => 1, 'status' => 'Approved']]), 'plan' => 'Staged ring deployment: pilot 20, broad 150, remainder.', 'backout' => 'Pause ring and uninstall offending KB via Intune.'],
            ['code' => 'CHG-0306', 'title' => 'NetSuite sandbox refresh', 'risk' => 'Low', 'state' => 'In progress', 'type' => 'Normal', 'owner_id' => 6, 'window_at' => $this->ago(2), 'duration' => '8h', 'impact' => 'Finance sandbox users', 'approvals' => json_encode([['by' => 1, 'status' => 'Approved'], ['by' => 6, 'status' => 'Approved']]), 'plan' => 'Refresh sandbox from production copy, re-apply integration keys.', 'backout' => 'Restore prior sandbox snapshot.'],
            ['code' => 'CHG-0298', 'title' => 'Decommission legacy file server FS-03', 'risk' => 'High', 'state' => 'Completed', 'type' => 'Normal', 'owner_id' => 3, 'window_at' => $this->ago(120), 'duration' => '3h', 'impact' => 'Legacy shares, Operations', 'approvals' => json_encode([['by' => 1, 'status' => 'Approved']]), 'plan' => 'Migrate remaining shares to SharePoint, power down after 14 day quiet period.', 'backout' => 'Power on and re-share; data retained for 30 days.'],
            ['code' => 'CHG-0295', 'title' => 'Enable conditional access for contractors', 'risk' => 'Medium', 'state' => 'Rejected', 'type' => 'Normal', 'owner_id' => 5, 'window_at' => $this->ago(200), 'duration' => '2h', 'impact' => '87 contractor accounts', 'approvals' => json_encode([['by' => 1, 'status' => 'Rejected']]), 'plan' => 'Apply device compliance policy to contractor group.', 'backout' => 'Remove policy assignment.'],
        ]);

        $db->table('problems')->insertBatch([
            ['code' => 'PRB-0044', 'title' => 'Intermittent VPN drops for Montréal users', 'status' => 'Root cause identified', 'priority' => 'High', 'owner_id' => 3, 'linked' => 0, 'opened_at' => $this->ago(96), 'cause' => 'MTU mismatch on the new ISP link fragments UDP 443 under load.', 'workaround' => 'Force TCP fallback in the client profile for Montréal.'],
            ['code' => 'PRB-0041', 'title' => 'Outlook search index rebuilds after reboot', 'status' => 'Under investigation', 'priority' => 'Medium', 'owner_id' => 4, 'linked' => 0, 'opened_at' => $this->ago(240), 'cause' => '—', 'workaround' => 'Rebuild index overnight; users advised to leave laptops powered on.'],
            ['code' => 'PRB-0038', 'title' => 'Badge printer jams on batch runs', 'status' => 'Resolved', 'priority' => 'Low', 'owner_id' => 4, 'linked' => 0, 'opened_at' => $this->ago(600), 'cause' => 'Third-party card stock outside tolerance.', 'workaround' => 'Use approved stock only; supplier switched.'],
        ]);

        $db->table('announcements')->insertBatch([
            ['title' => 'Planned VPN maintenance — Saturday 02:00-06:00 ET', 'body' => 'The Toronto VPN gateway will be upgraded. Remote access will be unavailable during the window. Montréal users should connect to the Amsterdam gateway if needed.', 'user_id' => 3, 'created_at' => $this->ago(5), 'level' => 'warn'],
            ['title' => 'Phishing campaign targeting payroll', 'body' => 'Several employees have received messages asking them to confirm banking details for direct deposit. Payroll will never ask for this by email. Use the Report Phish button.', 'user_id' => 5, 'created_at' => $this->ago(28), 'level' => 'alert'],
            ['title' => 'New self-service password reset is live', 'body' => 'You can now reset your own password without contacting the Service Desk. It takes about a minute and works from any device.', 'user_id' => 1, 'created_at' => $this->ago(70), 'level' => 'info'],
        ]);

        $db->table('slas')->insertBatch([
            ['name' => 'Priority 1 — Urgent', 'first_response' => '15 minutes', 'resolution' => '4 hours', 'hours' => '24×7', 'escalation' => 'Notify Service Desk Lead at 50%', 'active' => 1],
            ['name' => 'Priority 2 — High', 'first_response' => '1 hour', 'resolution' => '8 hours', 'hours' => 'Business hours', 'escalation' => 'Notify group supervisor at 75%', 'active' => 1],
            ['name' => 'Priority 3 — Medium', 'first_response' => '4 hours', 'resolution' => '2 days', 'hours' => 'Business hours', 'escalation' => 'Notify assignee at 90%', 'active' => 1],
            ['name' => 'Priority 4 — Low', 'first_response' => '8 hours', 'resolution' => '5 days', 'hours' => 'Business hours', 'escalation' => 'None', 'active' => 1],
            ['name' => 'VIP override', 'first_response' => '10 minutes', 'resolution' => '2 hours', 'hours' => '24×7', 'escalation' => 'Page on-call immediately', 'active' => 0],
        ]);

        $db->table('automations')->insertBatch([
            ['name' => 'Route password resets to Identity & Access', 'when_event' => 'Ticket is created', 'cond' => 'Subject contains "password"', 'action' => 'Assign to Identity & Access, set priority Medium', 'runs' => 0, 'active' => 1],
            ['name' => 'Escalate urgent tickets at 50% SLA', 'when_event' => 'Time is reached', 'cond' => 'Priority is Urgent and 50% of resolution SLA elapsed', 'action' => 'Email Service Desk Lead, add tag escalated', 'runs' => 0, 'active' => 1],
            ['name' => 'Auto-close resolved tickets after 5 days', 'when_event' => 'Time is reached', 'cond' => 'Status is Resolved for 5 days', 'action' => 'Set status Closed, send satisfaction survey', 'runs' => 0, 'active' => 1],
            ['name' => 'Flag VIP requesters', 'when_event' => 'Ticket is created', 'cond' => 'Requester is in group Executive', 'action' => 'Apply VIP override SLA, notify on-call', 'runs' => 0, 'active' => 0],
            ['name' => 'Nudge pending tickets', 'when_event' => 'Time is reached', 'cond' => 'Status is Pending for 3 days with no reply', 'action' => 'Email requester a reminder', 'runs' => 0, 'active' => 1],
        ]);

        $db->table('ticket_fields')->insertBatch([
            ['label' => 'Category', 'type' => 'Dropdown', 'required' => 1, 'agents' => 1, 'portal' => 1],
            ['label' => 'Subcategory', 'type' => 'Dropdown', 'required' => 0, 'agents' => 1, 'portal' => 1],
            ['label' => 'Impacted site', 'type' => 'Dropdown', 'required' => 0, 'agents' => 1, 'portal' => 1],
            ['label' => 'Cost centre', 'type' => 'Text', 'required' => 0, 'agents' => 1, 'portal' => 0],
            ['label' => 'Affected asset', 'type' => 'Lookup', 'required' => 0, 'agents' => 1, 'portal' => 1],
            ['label' => 'Root cause', 'type' => 'Paragraph', 'required' => 0, 'agents' => 1, 'portal' => 0],
        ]);

        $db->table('email_templates')->insertBatch([
            ['name' => 'Ticket received', 'trigger_event' => 'Ticket created', 'recipient' => 'Requester', 'subject' => 'We have your request — {{ticket.id}}'],
            ['name' => 'Agent replied', 'trigger_event' => 'Public reply sent', 'recipient' => 'Requester', 'subject' => 'Update on {{ticket.id}}'],
            ['name' => 'Resolution confirmed', 'trigger_event' => 'Status → Resolved', 'recipient' => 'Requester', 'subject' => '{{ticket.id}} is resolved — how did we do?'],
            ['name' => 'Assignment notice', 'trigger_event' => 'Ticket assigned', 'recipient' => 'Agent', 'subject' => '{{ticket.id}} assigned to you'],
            ['name' => 'SLA breach warning', 'trigger_event' => '80% of SLA', 'recipient' => 'Group', 'subject' => 'SLA warning — {{ticket.id}}'],
        ]);

        $db->table('canned_responses')->insertBatch([
            ['title' => 'Asking for more detail', 'body' => "Thanks for getting in touch. So I can pin this down, could you tell me:\n\n1. What you were doing when it happened\n2. The exact error text, or a screenshot\n3. Whether it happens on other networks\n\nI will pick this straight back up once I hear from you."],
            ['title' => 'Password reset done', 'body' => "Your password has been reset. You will be asked to set a new one at your next sign-in, and it applies to email, VPN, and every app behind single sign-on.\n\nIf the prompt does not appear within five minutes, reply here and I will re-check the enrolment."],
            ['title' => 'Waiting on a vendor', 'body' => "I have raised this with the vendor and have a case open with them. I will keep this ticket open and update you as soon as they come back — usually within one business day."],
            ['title' => 'Closing after resolution', 'body' => "Glad that sorted it. I am marking this resolved, but replying to this message reopens it within five days if anything comes back."],
        ]);

        $this->seedTickets();
    }

    private function seedTickets(): void
    {
        // [code, subject, requester, agent, group, status, priority, type, category, source,
        //  createdAgo, frDueAgo(neg = ahead), resDueAgo(neg = ahead), respondedAgo|null, resolvedAgo|null,
        //  tags, assets(ids), escalated, csat, body, tasks, conv]
        $tickets = [
            ['INC-2098', 'Cannot connect to VPN from home — error 812', 8, 3, 2, 'Open', 'High', 'Incident', 'Network', 'Portal', 5.2, 4.2, -1.1, 4.6, null, ['vpn', 'montreal'], [2], 1, null,
                'Since yesterday afternoon I get error 812 every time I try to connect from home. It works fine when I am in the Montréal office. I have restarted twice and reinstalled the client.',
                [['Check certificate expiry', 1, 3], ['Verify MTU on new ISP link', 0, 3]],
                [['reply', 3, 4.6, 'Thanks Camille — error 812 usually means the client certificate has expired. I am checking your enrolment record now. In the meantime, are you on the 5GHz band at home, and does it fail on a phone hotspot too?'],
                 ['reply', 8, 3.9, 'Same failure on my phone hotspot. 5GHz at home. Two colleagues here in Montréal are seeing it as well.'],
                 ['note', 3, 3.4, 'Third Montréal report today. Fragmentation on the new ISP link is the likely cause — linking to PRB-0044 rather than treating these as isolated.']]],
            ['INC-2097', 'Payroll system rejecting my sign-in after password change', 7, 5, 4, 'Pending', 'Medium', 'Incident', 'Access', 'Email', 9, 5, -20, 7.5, null, ['sso', 'workday'], [1], 0, null,
                'I reset my password this morning using the new self-service page. Everything else works but Workday keeps saying my credentials are invalid.',
                [],
                [['reply', 5, 7.5, 'Hi Jordan — Workday caches credentials separately for about 30 minutes after a reset. Could you sign out fully, close the browser, and try again? If it still fails I will force a directory sync.'],
                 ['reply', 7, 6.2, 'Tried that, still no luck. I need to approve timesheets before Friday.'],
                 ['note', 5, 6.0, 'Forced sync queued. Waiting on the next Workday connector run at the top of the hour.']]],
            ['INC-2096', 'Floor 2 printer jams on every job', 11, 4, 3, 'Open', 'Low', 'Incident', 'Printing', 'Phone', 26, 18, -62, 22, null, ['printing', 'mississauga'], [6], 0, null,
                'The printer outside the warehouse office jams on every job, even single pages. Paper tray 2. We are printing shipping labels by hand at the moment.',
                [['Order fuser replacement', 1, 4], ['Schedule engineer visit', 0, 4]],
                [['reply', 4, 22, 'A replacement fuser is on order and should arrive Thursday. Until then, floor 3 has an identical printer queue if you need labels urgently.']]],
            ['SR-4382', 'New laptop for incoming analyst — starts Monday', 12, 4, 3, 'Open', 'Medium', 'Service request', 'Hardware', 'Portal', 30, 26, -40, 27, null, ['onboarding'], [], 0, null,
                'New starter in Finance begins Monday. Standard spec is fine. Please ship to Toronto HQ, floor 3 reception.',
                [['Image laptop', 1, 4], ['Create directory account', 1, 5], ['Assign app profile', 0, 5]],
                [['reply', 4, 27, 'Booked in. The machine is imaged and I will have it at floor 3 reception by Friday afternoon with a dock and monitor.']]],
            ['INC-2095', 'Salesforce dashboards timing out', 9, 6, 5, 'Open', 'Urgent', 'Incident', 'Software', 'Chat', 1.6, 1.35, -2.4, 1.3, null, ['salesforce', 'reporting'], [], 1, null,
                'Every pipeline dashboard times out after about 30 seconds. The whole sales team is affected and we have a board review at 4pm.',
                [],
                [['reply', 6, 1.3, 'On it. I can reproduce the timeout on the pipeline dashboards and have opened a severity 2 case with Salesforce. Reports built on the accounts object still load, so use those for the board review if we run short of time.'],
                 ['note', 1, 1.0, 'Vendor case escalated. Board review at 16:00 — keep updates flowing every 30 minutes.']]],
            ['SR-4381', 'Figma licence for the brand team', 8, null, 5, 'New', 'Low', 'Service request', 'Software', 'Portal', 3.1, -4.9, -116, null, null, ['licence'], [], 0, null,
                'Three of us need Figma seats for the rebrand work. Cost centre MKT-204.', [], []],
            ['INC-2094', 'Meeting room Ontario has no display', 13, null, 1, 'New', 'Medium', 'Incident', 'Hardware', 'Walk-up', 0.7, -3.3, -47, null, null, ['av', 'rooms'], [], 0, null,
                'The screen in Ontario on floor 3 stays black no matter which cable I use. I have a client call in there at 2pm.', [], []],
            ['INC-2093', 'Suspicious email asking to confirm banking details', 7, 5, 4, 'Resolved', 'High', 'Incident', 'Security', 'Email', 48, 47, 40, 47.4, 41, ['phishing', 'security'], [], 0, [5, 'Answered within minutes and explained exactly what to look for.'],
                'I received an email that looks like it is from Payroll asking me to confirm my direct deposit details. I have not clicked anything.',
                [],
                [['reply', 5, 47.4, 'You did exactly the right thing. That message is a phishing attempt — Payroll never asks for banking details by email. I have blocked the sender across the tenant and pulled the message from other inboxes.'],
                 ['system', 5, 41, 'Status changed to Resolved']]],
            ['SR-4379', 'Guest Wi-Fi for the auditors — 3 days', 7, 2, 1, 'Resolved', 'Low', 'Service request', 'Network', 'Portal', 72, 70, 60, 71, 66, ['guest-wifi'], [], 0, [4, 'Quick, though I had to chase for the second voucher.'],
                'Four external auditors are on site Tuesday to Thursday and need network access.',
                [],
                [['reply', 2, 71, 'Four vouchers created and emailed to you, valid Tuesday 08:00 through Thursday 20:00.']]],
            ['INC-2092', 'Laptop battery drains in under two hours', 10, 4, 3, 'Pending', 'Medium', 'Incident', 'Hardware', 'Portal', 120, 116, -8, 117, null, ['hardware'], [7], 0, null,
                'Battery went from all-day to about 100 minutes over the past few weeks. Cycle count is 847 according to system report.',
                [],
                [['reply', 4, 117, '847 cycles is past the service threshold, so this is covered under warranty. I have raised it with the vendor — can you confirm which day next week suits for the swap?']]],
            ['INC-2091', 'Shared drive missing after file server move', 11, 3, 2, 'Closed', 'High', 'Incident', 'Access', 'Phone', 200, 199, 192, 199.2, 194, ['storage'], [], 0, [5, ''],
                'The Operations shared drive letter disappeared this morning. Nobody in the warehouse office can reach the manifests.',
                [],
                [['reply', 3, 199.2, 'That share moved to SharePoint during the FS-03 decommission. I have pushed a shortcut to your team and pinned the new location — the old drive letter will not come back.']]],
            ['SR-4378', 'Second monitor for home office', 9, 2, 1, 'Closed', 'Low', 'Service request', 'Hardware', 'Portal', 320, 316, 280, 317, 300, ['hardware', 'remote'], [], 0, [3, 'Took longer than the stated three days.'],
                'Working remote full time and could use a second screen for pipeline reviews.', [], []],
            ['INC-2090', 'Outlook search returns nothing after restart', 13, 4, 3, 'Open', 'Medium', 'Incident', 'Software', 'Email', 50, 46, 2, 47, null, ['outlook'], [], 0, null,
                'Every time I restart my laptop, Outlook search comes back empty until the afternoon. It has been happening for two weeks.',
                [],
                [['reply', 4, 47, 'This matches a known problem we are tracking as PRB-0041 — the search index rebuilds on boot. Leaving the laptop powered on overnight avoids it while we work on a permanent fix.']]],
            ['SR-4377', 'Access to Snowflake — read only', 10, 5, 4, 'Pending', 'Low', 'Service request', 'Access', 'Portal', 60, 56, -30, 58, null, ['access', 'data'], [], 0, null,
                'Need read access to the analytics warehouse for a capacity model. Manager has approved by email.',
                [],
                [['reply', 5, 58, 'Approval received. Waiting on the data owner to confirm which schemas you should see — I have nudged them today.']]],
            ['INC-2089', 'Badge reader at loading dock offline', 14, null, 1, 'New', 'High', 'Incident', 'Facilities', 'Phone', 0.35, -0.65, -7.6, null, null, ['access', 'mississauga'], [], 0, null,
                'The badge reader at the loading dock is dead — no lights. Drivers are being let in manually which we should not be doing.', [], []],
            ['INC-2088', 'Teams audio cuts out in large meetings', 12, 2, 1, 'Open', 'Low', 'Incident', 'Software', 'Chat', 80, 74, -36, 76, null, ['teams'], [], 0, null,
                'In meetings over about 30 people my audio drops every few minutes. Fine in small calls.',
                [],
                [['reply', 2, 76, 'Could you run the network report from Teams during the next large meeting and attach it here? That will show whether packets are being dropped upstream or locally.']]],
        ];

        $db = $this->db;
        $sites = [7 => 'Toronto HQ', 8 => 'Montréal', 9 => 'Remote', 10 => 'Toronto HQ', 11 => 'Mississauga', 12 => 'Toronto HQ', 13 => 'Vancouver', 14 => 'Mississauga'];

        foreach ($tickets as $t) {
            [$code, $subject, $req, $agent, $group, $status, $priority, $type, $category, $source,
             $createdAgo, $frAgo, $resAgo, $respAgo, $resolvedAgo, $tags, $assets, $escalated, $csat, $body, $tasks, $conv] = $t;

            $created = $this->ago($createdAgo);
            $updated = $created;
            foreach ($conv as $c) {
                $updated = max($updated, $this->ago($c[2]));
            }

            $db->table('tickets')->insert([
                'code' => $code, 'subject' => $subject, 'requester_id' => $req, 'agent_id' => $agent,
                'group_id' => $group, 'status' => $status, 'priority' => $priority, 'type' => $type,
                'category' => $category, 'source' => $source, 'site' => $sites[$req] ?? '—',
                'tags' => json_encode($tags), 'escalated' => $escalated,
                'csat_score' => $csat[0] ?? null, 'csat_comment' => $csat[1] ?? null,
                'fr_due' => $frAgo < 0 ? $this->ahead(-$frAgo) : $this->ago($frAgo),
                'res_due' => $resAgo < 0 ? $this->ahead(-$resAgo) : $this->ago($resAgo),
                'responded_at' => $respAgo !== null ? $this->ago($respAgo) : null,
                'resolved_at' => $resolvedAgo !== null ? $this->ago($resolvedAgo) : null,
                'created_at' => $created, 'updated_at' => $updated,
            ]);
            $tid = $db->insertID();

            $db->table('ticket_messages')->insert([
                'ticket_id' => $tid, 'kind' => 'description', 'user_id' => $req,
                'body' => $body, 'attachments' => '[]', 'created_at' => $created,
            ]);
            foreach ($conv as $c) {
                $db->table('ticket_messages')->insert([
                    'ticket_id' => $tid, 'kind' => $c[0], 'user_id' => $c[1],
                    'body' => $c[3], 'attachments' => '[]', 'created_at' => $this->ago($c[2]),
                ]);
            }
            foreach ($tasks as $task) {
                $db->table('ticket_tasks')->insert([
                    'ticket_id' => $tid, 'title' => $task[0], 'done' => $task[1], 'owner_id' => $task[2],
                ]);
            }
            foreach ($assets as $aid) {
                $db->table('ticket_assets')->insert(['ticket_id' => $tid, 'asset_id' => $aid]);
            }
        }
    }
}
