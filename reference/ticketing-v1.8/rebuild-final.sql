/*
   FULL DESTRUCTIVE REBUILD
   Take a database backup first. Select the application database, then run:
   SOURCE rebuild-final.sql;
   The final schema is loaded from schema.sql in this same package directory.
*/

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS
    food_ticket_group_uid_history,
    food_ticket_daily_absence,
    food_ticket_group_runs,
    food_ticket_group_members,
    food_ticket_groups,
    food_ticket_card_map,
    ticket_ola_alerts,
    ola_policies,
    problem_ticket_links,
    problem_records,
    change_ticket_links,
    change_approvals,
    change_records,
    asset_relations,
    asset_profiles,
    org_unit_managers,
    org_units,
    domain_scan_queue,
    domain_scan_runs,
    asset_history_events,
    asset_inventory_history,
    asset_peripherals,
    asset_graphics_adapters,
    asset_storage_devices,
    asset_memory_modules,
    assets,
    holidays,
    knowledge_articles,
    ticket_events,
    ticket_sla_alerts,
    login_attempts,
    notifications,
    activity_logs,
    role_permissions,
    ticket_attachments,
    ticket_ratings,
    ticket_messages,
    tickets,
    service_catalog_fields,
    service_catalog,
    categories,
    cd_dvd_records,
    cd_dvd_types,
    traffic_visits,
    traffic_destinations,
    handling_units,
    departments,
    users,
    settings,
    system_logs,
    api_rate_limits,
    ticket_number_seq,
    food_ticket_events,
    food_ticket_worker_status,
    food_ticket_guest_cards,
    food_ticket_print_log,
    food_ticket_config;

SET FOREIGN_KEY_CHECKS = 1;

SOURCE schema.sql;
