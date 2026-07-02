ALTER TABLE cl_teams
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER phone;
