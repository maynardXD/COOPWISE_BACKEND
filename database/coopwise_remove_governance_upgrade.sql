-- Back up the database before running this migration.
-- This removes the retired meetings and voting feature and its stored records.
DROP TABLE IF EXISTS cooperative_meeting_votes;
DROP TABLE IF EXISTS cooperative_meeting_options;
DROP TABLE IF EXISTS cooperative_meetings;
