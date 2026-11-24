CREATE TABLE IF NOT EXISTS darmutiara_kajian (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title varchar(180) NOT NULL,
  speaker varchar(120) NOT NULL,
  starts_at datetime NOT NULL,
  location varchar(240) NOT NULL,
  meeting_url varchar(500) NULL,
  recording_url varchar(500) NULL,
  product_id int unsigned NULL,
  published tinyint(1) NOT NULL DEFAULT 0,
  KEY kajian_schedule (published, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS darmutiara_kajian_participants (
  kajian_id int unsigned NOT NULL,
  user_id int unsigned NOT NULL,
  registered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (kajian_id, user_id),
  FOREIGN KEY (kajian_id) REFERENCES darmutiara_kajian(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS darmutiara_kajian_posts (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kajian_id int unsigned NOT NULL,
  user_id int unsigned NOT NULL,
  body text NOT NULL,
  hidden tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY kajian_discussion (kajian_id, id),
  FOREIGN KEY (kajian_id) REFERENCES darmutiara_kajian(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
