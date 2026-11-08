CREATE TABLE IF NOT EXISTS cooperative_meetings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(1000) NULL,
    meeting_date DATE NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_cooperative_meetings_status_date (status, meeting_date),
    CONSTRAINT fk_cooperative_meeting_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_meeting_options (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meeting_id INT UNSIGNED NOT NULL,
    option_text VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meeting_option_text (meeting_id, option_text),
    KEY idx_meeting_options_meeting (meeting_id),
    CONSTRAINT fk_meeting_option_meeting FOREIGN KEY (meeting_id) REFERENCES cooperative_meetings(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cooperative_meeting_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meeting_id INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meeting_vote_member (meeting_id, member_id),
    KEY idx_meeting_votes_option (option_id),
    CONSTRAINT fk_meeting_vote_meeting FOREIGN KEY (meeting_id) REFERENCES cooperative_meetings(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_meeting_vote_option FOREIGN KEY (option_id) REFERENCES cooperative_meeting_options(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_meeting_vote_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
