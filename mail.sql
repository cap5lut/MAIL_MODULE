CREATE TABLE IF NOT EXISTS mails (`id` int NOT NULL PRIMARY KEY AUTO_INCREMENT, `sendtime` INT NOT NULL, `sender` VARCHAR(25) NOT NULL, `recipient` VARCHAR(25) NOT NULL, `message` VARCHAR(500) NOT NULL);
CREATE TABLE IF NOT EXISTS mails_bots (`name` VARCHAR(25) NOT NULL PRIMARY KEY);
