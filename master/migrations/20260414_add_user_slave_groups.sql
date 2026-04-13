-- Module A: User Slave Sharing Groups

CREATE TABLE IF NOT EXISTS user_slave_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    owner_user_id INT NOT NULL,
    invite_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invite_code (invite_code)
);

CREATE TABLE IF NOT EXISTS user_slave_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_user (group_id, user_id)
);

ALTER TABLE nodes
    ADD COLUMN sharing_mode ENUM('private', 'group_shared') NOT NULL DEFAULT 'private',
    ADD COLUMN user_slave_group_id INT NULL;
