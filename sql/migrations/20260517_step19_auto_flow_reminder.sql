SET NAMES utf8mb4;

UPDATE users
SET annual_reminder = NULL
WHERE annual_reminder IS NOT NULL;
