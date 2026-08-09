CREATE TABLE IF NOT EXISTS knowledgebase_topics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_key VARCHAR(190) NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_knowledgebase_topics_key (topic_key),
    KEY idx_knowledgebase_topics_reader (is_pinned, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledgebase_articles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    author_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    excerpt TEXT NULL,
    body_html MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_knowledgebase_articles_topic FOREIGN KEY (topic_id) REFERENCES knowledgebase_topics(id),
    CONSTRAINT fk_knowledgebase_articles_author FOREIGN KEY (author_user_id) REFERENCES users(id),
    UNIQUE KEY uniq_knowledgebase_article_locale (topic_id, locale),
    KEY idx_knowledgebase_articles_reader (locale, status, topic_id),
    KEY idx_knowledgebase_articles_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
