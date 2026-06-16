-- MySQL 8.x compatibility cleanup for legacy MariaDB zero dates.
-- Run this once on an already-imported database if old records still contain 0000-00-00.

USE crossroad_solutions_inventory_management;

UPDATE project_inventory
SET po_date = NULL
WHERE CAST(po_date AS CHAR) = '0000-00-00';

UPDATE project_inventory
SET contract_start = NULL
WHERE CAST(contract_start AS CHAR) = '0000-00-00';

UPDATE project_inventory
SET contract_end = NULL
WHERE CAST(contract_end AS CHAR) = '0000-00-00';

UPDATE asset_inventory
SET date_received = NULL
WHERE CAST(date_received AS CHAR) = '0000-00-00';

UPDATE asset_stockin_history
SET date_received = NULL
WHERE CAST(date_received AS CHAR) = '0000-00-00';

UPDATE server_inventory
SET date_testing = NULL
WHERE CAST(date_testing AS CHAR) = '0000-00-00';

UPDATE server_stockin_history
SET date_testing = NULL
WHERE CAST(date_testing AS CHAR) = '0000-00-00';

UPDATE contract_tasks
SET task_start_date = NULL
WHERE CAST(task_start_date AS CHAR) = '0000-00-00';

UPDATE contract_tasks
SET task_end_date = NULL
WHERE CAST(task_end_date AS CHAR) = '0000-00-00';
