<?php
// ============================================
// FILE: analytics_reports.php
// PURPOSE: Registry of strategic reports. Each report is a list of blocks
// evaluated by analytics_lib.php. SQL here is static; filters are bound.
// ============================================

function analyticsCategories(): array
{
    return [
        'strategic' => ['Strategic overview', 'fa-compass', 'primary', 'Whole-service scorecards and trends for leadership decisions'],
        'anc' => ['Antenatal care', 'fa-stethoscope', 'info', 'Booking, contacts, nutrition, preventive care and danger signs'],
        'hiv' => ['HIV, PMTCT & infections', 'fa-shield-virus', 'danger', 'Testing cascade, positivity, syphilis and hepatitis B'],
        'delivery' => ['Labour & delivery', 'fa-baby', 'rose', 'Volumes, modes, complications, shifts and maternal safety'],
        'newborn' => ['Newborn outcomes', 'fa-baby-carriage', 'warning', 'Stillbirths, birth weight, newborn care and gestation'],
        'pnc' => ['Postnatal & family planning', 'fa-hand-holding-heart', 'success', 'PNC coverage, family planning and infant follow-up'],
        'child' => ['Child health', 'fa-child', 'teal', 'Growth visits and immunisation performance'],
        'referral' => ['Referrals, screening & appointments', 'fa-share-from-square', 'amber', 'Referral flow, screening yield and appointment attendance'],
        'community' => ['Catchment & equity', 'fa-map-location-dot', 'purple', 'Who and where we serve, and gaps between groups'],
        'ops' => ['Operations, staff & stock', 'fa-gears', 'slate', 'Staff activity, data quality, usage, stock and workforce'],
    ];
}

function analyticsReports(): array
{
    $hivPos = "'TRR','TRR+','TRRK'";
    $hivNeg = "'TR','TR+','TRK','TRV'";
    $hivTested = "'T','TR','TR+','TRK','TRV','TRR','TRR+','TRRK'";
    $age = "CASE WHEN p.age IS NULL THEN NULL WHEN p.age < 20 THEN '<20' WHEN p.age < 25 THEN '20-24' WHEN p.age < 35 THEN '25-34' ELSE '35+' END";
    $ageBands = ANALYTICS_AGE_BANDS;
    $bb = static fn(string $dr, string $b): string => "IF(b.id IS NULL, dr.$dr, b.$b)";
    $bw = $bb('baby_weight_kg', 'baby_weight_kg');
    $bc = $bb('baby_condition_at_discharge', 'condition_at_discharge');
    $stillbirth = "$bc IN ('FSB','MSB')";
    $bool = static fn(string $expr): string => "SUM(COALESCE($expr, 0) = 1)";
    $weekdays = ['0' => 'Monday', '1' => 'Tuesday', '2' => 'Wednesday', '3' => 'Thursday', '4' => 'Friday', '5' => 'Saturday', '6' => 'Sunday'];
    $adminRoles = ['admin', 'staff_admin', 'manager'];

    $r = [];

    // ---------------- STRATEGIC ----------------
    $r['scorecard'] = ['cat' => 'strategic', 'title' => 'Executive scorecard', 'icon' => 'fa-gauge-high',
        'desc' => 'Headline volumes across every service, compared with the previous period.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'patients', 'items' => [['label' => 'Patients registered', 'expr' => 'COUNT(*)', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'episodes', 'items' => [['label' => 'New pregnancies booked', 'expr' => 'COUNT(*)', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'anc', 'items' => [['label' => 'ANC visits', 'expr' => 'COUNT(*)', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [['label' => 'Deliveries', 'expr' => 'COUNT(*)']]],
            ['type' => 'kpis', 'source' => 'pnc', 'items' => [['label' => 'PNC visits', 'expr' => 'COUNT(*)', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'child_visit', 'items' => [['label' => 'Child health visits', 'expr' => 'COUNT(*)', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'screening', 'items' => [['label' => 'Screenings', 'expr' => 'COUNT(*)']]],
            ['type' => 'kpis', 'source' => 'referral', 'items' => [['label' => 'Referrals', 'expr' => 'COUNT(*)']]],
            ['type' => 'kpichart', 'title' => 'Service volumes: current vs previous period'],
            ['type' => 'kpis', 'source' => 'episodes', 'title' => 'Quality at a glance', 'items' => [
                ['label' => 'Early booking (≤12 wks)', 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)', 'fmt' => 'pct', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Caesarean rate', 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
            ['type' => 'kpis', 'source' => 'baby', 'items' => [
                ['label' => 'Stillbirths per 1,000 births', 'num' => "SUM($stillbirth)", 'den' => 'COUNT(*)', 'fmt' => 'dec', 'scale' => 1000, 'good' => 'down'],
                ['label' => 'Low birth weight (<2.5 kg)', 'num' => "SUM($bw < 2.5)", 'den' => "SUM($bw IS NOT NULL)", 'fmt' => 'pct', 'good' => 'down']]],
            ['type' => 'kpis', 'source' => 'appointment', 'items' => [
                ['label' => 'Appointment attendance', 'num' => "SUM(a.status = 'completed')", 'den' => "SUM(a.status IN ('completed','missed'))", 'fmt' => 'pct', 'good' => 'up']]],
        ]];

    $r['service_trends'] = ['cat' => 'strategic', 'title' => 'Service volume trends', 'icon' => 'fa-chart-line',
        'desc' => 'How busy each clinic area is over time, against the earlier period.',
        'blocks' => [
            ['type' => 'trend', 'source' => 'anc', 'title' => 'ANC visits', 'series' => [['label' => 'ANC visits', 'expr' => 'COUNT(*)']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Deliveries', 'series' => [['label' => 'Deliveries', 'expr' => 'COUNT(*)', 'color' => '#DB2777']]],
            ['type' => 'trend', 'source' => 'pnc', 'title' => 'Postnatal visits', 'series' => [['label' => 'PNC visits', 'expr' => 'COUNT(*)', 'color' => '#10B981']]],
            ['type' => 'trend', 'source' => 'child_visit', 'title' => 'Child health visits', 'series' => [['label' => 'Child visits', 'expr' => 'COUNT(*)', 'color' => '#0D9488']]],
            ['type' => 'trend', 'source' => 'referral', 'title' => 'Referrals', 'series' => [['label' => 'Referrals', 'expr' => 'COUNT(*)', 'color' => '#F59E0B']]],
            ['type' => 'trend', 'source' => 'patients', 'title' => 'New patient registrations', 'series' => [['label' => 'Registrations', 'expr' => 'COUNT(*)', 'color' => '#7C3AED']]],
        ]];

    $r['indicator_trends'] = ['cat' => 'strategic', 'title' => 'Quality indicator trends', 'icon' => 'fa-chart-column',
        'desc' => 'Key rates tracked over time: booking, caesarean, low birth weight, stillbirth, HIV positivity.',
        'blocks' => [
            ['type' => 'trend', 'source' => 'episodes', 'title' => 'Early ANC booking rate', 'fmt' => 'pct', 'series' => [['label' => 'Early booking %', 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Caesarean section rate', 'fmt' => 'pct', 'series' => [['label' => 'Caesarean %', 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'color' => '#DB2777']]],
            ['type' => 'trend', 'source' => 'baby', 'title' => 'Low birth weight rate', 'fmt' => 'pct', 'series' => [['label' => 'LBW %', 'num' => "SUM($bw < 2.5)", 'den' => "SUM($bw IS NOT NULL)", 'color' => '#F59E0B']]],
            ['type' => 'trend', 'source' => 'baby', 'title' => 'Stillbirths per 1,000 births', 'fmt' => 'dec', 'series' => [['label' => 'Stillbirth rate', 'num' => "SUM($stillbirth)", 'den' => 'COUNT(*)', 'scale' => 1000, 'color' => '#EF4444']]],
            ['type' => 'trend', 'source' => 'anc', 'title' => 'HIV positivity among mothers tested', 'fmt' => 'pct', 'series' => [['label' => 'HIV positive %', 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN pe.id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos,$hivNeg) THEN pe.id END)", 'color' => '#EF4444']]],
            ['type' => 'trend', 'source' => 'appointment', 'title' => 'Appointment attendance', 'fmt' => 'pct', 'series' => [['label' => 'Attended %', 'num' => "SUM(a.status = 'completed')", 'den' => "SUM(a.status IN ('completed','missed'))", 'color' => '#10B981']]],
        ]];

    // ---------------- ANTENATAL ----------------
    $r['anc_volume'] = ['cat' => 'anc', 'title' => 'ANC attendance & workload', 'icon' => 'fa-calendar-check',
        'desc' => 'Visits, mothers seen, visit sequence and busiest days.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'anc', 'items' => [
                ['label' => 'ANC visits', 'expr' => 'COUNT(*)', 'good' => 'up'],
                ['label' => 'Mothers seen', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'good' => 'up'],
                ['label' => 'Visits per mother', 'num' => 'COUNT(*)', 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'fmt' => 'dec2', 'good' => 'up']]],
            ['type' => 'trend', 'source' => 'anc', 'title' => 'ANC visits and mothers seen', 'series' => [
                ['label' => 'Visits', 'expr' => 'COUNT(*)'], ['label' => 'Mothers', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)']]],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Visits by contact number', 'dim' => 'LEAST(av.visit_number, 8)', 'expr' => 'COUNT(*)', 'order' => 'label', 'labels' => ['8' => '8+'], 'dim_label' => 'Contact no.'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Visits by weekday', 'dim' => 'WEEKDAY(av.visit_date)', 'expr' => 'COUNT(*)', 'labels' => $weekdays, 'fixed' => array_keys($weekdays), 'limit' => 0, 'dim_label' => 'Weekday'],
        ]];

    $r['anc_booking'] = ['cat' => 'anc', 'title' => 'Early booking & gestation at first visit', 'icon' => 'fa-clock',
        'desc' => 'Share of mothers booking by 12 weeks, and who books late.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'episodes', 'items' => [
                ['label' => 'Pregnancies booked', 'expr' => 'COUNT(*)', 'good' => 'up'],
                ['label' => 'Early booking (≤12 wks)', 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Average GA at booking (wks)', 'expr' => 'AVG(pe.booking_ga_weeks)', 'fmt' => 'dec', 'good' => 'down']]],
            ['type' => 'trend', 'source' => 'episodes', 'title' => 'Early booking rate', 'fmt' => 'pct', 'series' => [['label' => 'Early booking %', 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)']]],
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Gestation at booking', 'dim' => "CASE WHEN pe.booking_ga_weeks IS NULL THEN NULL WHEN pe.booking_ga_weeks <= 12 THEN '≤12 wks' WHEN pe.booking_ga_weeks <= 20 THEN '13–20 wks' WHEN pe.booking_ga_weeks <= 28 THEN '21–28 wks' ELSE '29+ wks' END", 'expr' => 'COUNT(*)', 'fixed' => ['≤12 wks', '13–20 wks', '21–28 wks', '29+ wks'], 'limit' => 0, 'dim_label' => 'Gestation'],
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Early booking rate by age group', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group'],
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Late booking by village (top 10)', 'dim' => 'p.village', 'expr' => "SUM(pe.booking_timing = 'late')", 'limit' => 10, 'dim_label' => 'Village', 'color' => '#F59E0B'],
        ]];

    $r['anc_contacts'] = ['cat' => 'anc', 'title' => 'ANC contact completion (4+ and 8+)', 'icon' => 'fa-list-check',
        'desc' => 'Among pregnancies due in the period, how many completed the recommended number of ANC contacts.',
        'blocks' => [
            ['type' => 'note', 'text' => 'Cohort = pregnancies whose expected delivery date (EDD) falls in the selected period, excluding aborted or transferred pregnancies. This avoids penalising mothers who are still early in pregnancy.'],
            ['type' => 'kpis', 'source' => 'anc_cycle', 'where' => "pe.status NOT IN ('aborted','transferred')", 'items' => [
                ['label' => 'Pregnancies due', 'expr' => 'COUNT(*)'],
                ['label' => 'ANC 1+ coverage', 'num' => 'SUM(COALESCE(ac.visits,0) >= 1)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'ANC 4+ coverage', 'num' => 'SUM(COALESCE(ac.visits,0) >= 4)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'ANC 8+ coverage', 'num' => 'SUM(COALESCE(ac.visits,0) >= 8)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up']]],
            ['type' => 'trend', 'source' => 'anc_cycle', 'where' => "pe.status NOT IN ('aborted','transferred')", 'title' => 'ANC 4+ coverage by month of EDD', 'fmt' => 'pct', 'series' => [
                ['label' => 'ANC 4+ %', 'num' => 'SUM(COALESCE(ac.visits,0) >= 4)', 'den' => 'COUNT(*)']]],
            ['type' => 'breakdown', 'source' => 'anc_cycle', 'where' => "pe.status NOT IN ('aborted','transferred')", 'title' => 'Number of ANC contacts attended', 'dim' => "CASE WHEN COALESCE(ac.visits,0) >= 8 THEN '8+' ELSE CAST(COALESCE(ac.visits,0) AS CHAR) END", 'expr' => 'COUNT(*)', 'fixed' => ['0', '1', '2', '3', '4', '5', '6', '7', '8+'], 'limit' => 0, 'dim_label' => 'Contacts'],
            ['type' => 'breakdown', 'source' => 'anc_cycle', 'where' => "pe.status NOT IN ('aborted','transferred')", 'title' => 'ANC 4+ coverage by age group', 'fmt' => 'pct', 'dim' => $age, 'num' => 'SUM(COALESCE(ac.visits,0) >= 4)', 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group'],
        ]];

    $r['anc_nutrition'] = ['cat' => 'anc', 'title' => 'Maternal nutrition (MUAC & weight)', 'icon' => 'fa-apple-whole',
        'desc' => 'Malnutrition among mothers at ANC, measured by MUAC colour band.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'anc', 'where' => "av.muac_color IN ('G','Y','R')", 'items' => [
                ['label' => 'Mothers with MUAC measured', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Malnourished (MUAC <23 cm)', 'num' => "COUNT(DISTINCT CASE WHEN av.muac_color IN ('Y','R') THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'Average MUAC (cm)', 'expr' => 'AVG(av.muac_cm)', 'fmt' => 'dec']]],
            ['type' => 'trend', 'source' => 'anc', 'where' => "av.muac_color IN ('G','Y','R')", 'title' => 'Share of visits with MUAC below 23 cm', 'fmt' => 'pct', 'series' => [
                ['label' => 'MUAC <23 cm %', 'num' => "SUM(av.muac_color IN ('Y','R'))", 'den' => 'COUNT(*)', 'color' => '#F59E0B']]],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'MUAC status of visits', 'dim' => 'av.muac_color', 'expr' => 'COUNT(*)', 'labels' => ['G' => 'Normal (≥23)', 'Y' => 'Moderate (19–22.9)', 'R' => 'Severe (<19)', 'ND' => 'Not done'], 'fixed' => ['G', 'Y', 'R', 'ND'], 'limit' => 0, 'dim_label' => 'MUAC'],
            ['type' => 'breakdown', 'source' => 'anc', 'where' => "av.muac_color IN ('G','Y','R')", 'title' => 'Malnutrition by age group', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM(av.muac_color IN ('Y','R'))", 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#F59E0B'],
        ]];

    $r['anc_interventions'] = ['cat' => 'anc', 'title' => 'Preventive care coverage', 'icon' => 'fa-syringe',
        'desc' => 'Tetanus, IPTp malaria prophylaxis, nets, iron/folate and deworming among mothers seen.',
        'blocks' => [
            ['type' => 'metrics', 'source' => 'anc', 'title' => 'Coverage among mothers seen in the period', 'items' => [
                ['label' => 'TT2+ received', 'num' => 'COUNT(DISTINCT CASE WHEN av.tt_dose_received >= 2 THEN av.pregnancy_episode_id END)', 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'IPTp (any dose)', 'num' => "COUNT(DISTINCT CASE WHEN av.iptp_dose IS NOT NULL AND av.iptp_dose <> '' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'IPTp 2+ doses', 'num' => "COUNT(DISTINCT CASE WHEN av.iptp_dose REGEXP '^SP[2-9]' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'LLIN (net) given', 'num' => 'COUNT(DISTINCT CASE WHEN av.llin_given = 1 THEN av.pregnancy_episode_id END)', 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Iron/folate given', 'num' => 'COUNT(DISTINCT CASE WHEN av.iron_folate_tablets > 0 THEN av.pregnancy_episode_id END)', 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Mebendazole given', 'num' => "COUNT(DISTINCT CASE WHEN av.mebendazole_status IN ('given','completed') THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
            ]],
            ['type' => 'trend', 'source' => 'anc', 'title' => 'TT2+ and IPTp coverage per month (mothers seen)', 'fmt' => 'pct', 'series' => [
                ['label' => 'TT2+ %', 'num' => 'COUNT(DISTINCT CASE WHEN av.tt_dose_received >= 2 THEN av.pregnancy_episode_id END)', 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'IPTp %', 'num' => "COUNT(DISTINCT CASE WHEN av.iptp_dose IS NOT NULL AND av.iptp_dose <> '' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)']]],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Tetanus dose given at visit (mothers)', 'dim' => 'av.tt_dose_received', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'order' => 'label', 'labels' => ['1' => 'TT1', '2' => 'TT2', '3' => 'TT3', '4' => 'TT4', '5' => 'TT5'], 'limit' => 0, 'dim_label' => 'Dose'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'IPTp dose given at visit (mothers)', 'dim' => 'UPPER(av.iptp_dose)', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'order' => 'label', 'where' => "av.iptp_dose IS NOT NULL AND av.iptp_dose <> ''", 'limit' => 0, 'dim_label' => 'Dose'],
        ]];

    $r['anc_risk'] = ['cat' => 'anc', 'title' => 'Hypertension & danger signs', 'icon' => 'fa-heart-pulse',
        'desc' => 'Blood pressure categories, hypertension trend and referrals made at ANC.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'anc', 'where' => 'av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL', 'items' => [
                ['label' => 'Visits with BP recorded', 'expr' => 'COUNT(*)'],
                ['label' => 'Hypertensive visits (≥140/90)', 'num' => 'SUM(av.bp_systolic >= 140 OR av.bp_diastolic >= 90)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'Severe (≥160/110)', 'num' => 'SUM(av.bp_systolic >= 160 OR av.bp_diastolic >= 110)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down']]],
            ['type' => 'trend', 'source' => 'anc', 'where' => 'av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL', 'title' => 'Hypertension at ANC visits', 'fmt' => 'pct', 'series' => [
                ['label' => '≥140/90 %', 'num' => 'SUM(av.bp_systolic >= 140 OR av.bp_diastolic >= 90)', 'den' => 'COUNT(*)', 'color' => '#F59E0B'],
                ['label' => '≥160/110 %', 'num' => 'SUM(av.bp_systolic >= 160 OR av.bp_diastolic >= 110)', 'den' => 'COUNT(*)', 'color' => '#EF4444']]],
            ['type' => 'breakdown', 'source' => 'anc', 'where' => 'av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL', 'title' => 'Blood pressure category', 'dim' => "CASE WHEN av.bp_systolic >= 160 OR av.bp_diastolic >= 110 THEN 'Severe' WHEN av.bp_systolic >= 140 OR av.bp_diastolic >= 90 THEN 'Hypertensive' WHEN av.bp_systolic < 90 OR av.bp_diastolic < 60 THEN 'Low' ELSE 'Normal' END", 'expr' => 'COUNT(*)', 'fixed' => ['Normal', 'Low', 'Hypertensive', 'Severe'], 'limit' => 0, 'dim_label' => 'Category'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Hypertension by age group', 'fmt' => 'pct', 'where' => 'av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL', 'dim' => $age, 'num' => 'SUM(av.bp_systolic >= 140 OR av.bp_diastolic >= 90)', 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#F59E0B'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Referral type at ANC', 'dim' => 'av.referral_type', 'expr' => 'COUNT(*)', 'where' => 'av.referral_type IS NOT NULL', 'labels' => ['REF_OUT' => 'Referred out', 'REF_IN' => 'Referred in', 'C_REF_IN' => 'Community referred in', 'FSG' => 'Facility support group'], 'limit' => 0, 'dim_label' => 'Type'],
        ]];

    // ---------------- HIV ----------------
    $r['hiv_cascade'] = ['cat' => 'hiv', 'title' => 'HIV testing & PMTCT cascade (ANC)', 'icon' => 'fa-filter',
        'desc' => 'From mothers seen to tested, positive and partner follow-up.',
        'blocks' => [
            ['type' => 'metrics', 'source' => 'anc', 'title' => 'Cascade (mothers, counts)', 'fmt' => 'int', 'items' => [
                ['label' => 'Mothers seen', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Tested for HIV', 'expr' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivTested) THEN av.pregnancy_episode_id END)"],
                ['label' => 'Result known', 'expr' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos,$hivNeg) THEN av.pregnancy_episode_id END)"],
                ['label' => 'HIV positive', 'expr' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN av.pregnancy_episode_id END)"],
                ['label' => 'Partner tested', 'expr' => "COUNT(DISTINCT CASE WHEN av.partner_result_code IS NOT NULL AND av.partner_result_code <> '' THEN av.pregnancy_episode_id END)"],
                ['label' => 'Partner linked to ART', 'expr' => 'COUNT(DISTINCT CASE WHEN av.partner_linked_to_art = 1 THEN av.pregnancy_episode_id END)'],
            ]],
            ['type' => 'kpis', 'source' => 'anc', 'items' => [
                ['label' => 'HIV testing coverage', 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivTested) THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Positivity (result known)', 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN av.pregnancy_episode_id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos,$hivNeg) THEN av.pregnancy_episode_id END)", 'fmt' => 'pct'],
                ['label' => 'High eMTCT risk mothers', 'num' => "COUNT(DISTINCT CASE WHEN av.emtct_risk = 'High' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)', 'fmt' => 'pct', 'good' => 'down']]],
            ['type' => 'trend', 'source' => 'anc', 'title' => 'HIV testing coverage and positivity', 'fmt' => 'pct', 'series' => [
                ['label' => 'Tested %', 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivTested) THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Positive %', 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN av.pregnancy_episode_id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos,$hivNeg) THEN av.pregnancy_episode_id END)", 'color' => '#EF4444']]],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'HIV positivity by age group', 'fmt' => 'pct', 'dim' => $age, 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN av.pregnancy_episode_id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos,$hivNeg) THEN av.pregnancy_episode_id END)", 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#EF4444'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'HIV positive mothers by village (top 10)', 'dim' => 'p.village', 'expr' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN av.pregnancy_episode_id END)", 'limit' => 10, 'dim_label' => 'Village', 'color' => '#EF4444'],
        ]];

    $r['syphilis_hepb'] = ['cat' => 'hiv', 'title' => 'Syphilis & hepatitis B screening', 'icon' => 'fa-vial-virus',
        'desc' => 'Screening coverage and reactive results during ANC.',
        'blocks' => [
            ['type' => 'metrics', 'source' => 'anc', 'title' => 'Screening coverage and reactivity (mothers)', 'items' => [
                ['label' => 'Syphilis tested', 'num' => "COUNT(DISTINCT CASE WHEN av.syphilis_result IS NOT NULL AND av.syphilis_result <> '' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Syphilis reactive', 'num' => "COUNT(DISTINCT CASE WHEN av.syphilis_result IN ('Rx','+Ve') THEN av.pregnancy_episode_id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.syphilis_result IS NOT NULL AND av.syphilis_result <> '' THEN av.pregnancy_episode_id END)"],
                ['label' => 'Hepatitis B tested', 'num' => "COUNT(DISTINCT CASE WHEN av.hepatitis_b_result IS NOT NULL AND av.hepatitis_b_result <> '' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Hepatitis B positive', 'num' => "COUNT(DISTINCT CASE WHEN av.hepatitis_b_result IN ('Rx','+Ve') THEN av.pregnancy_episode_id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.hepatitis_b_result IS NOT NULL AND av.hepatitis_b_result <> '' THEN av.pregnancy_episode_id END)"],
            ]],
            ['type' => 'trend', 'source' => 'anc', 'title' => 'Syphilis and hepatitis B screening coverage', 'fmt' => 'pct', 'series' => [
                ['label' => 'Syphilis tested %', 'num' => "COUNT(DISTINCT CASE WHEN av.syphilis_result IS NOT NULL AND av.syphilis_result <> '' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Hep B tested %', 'num' => "COUNT(DISTINCT CASE WHEN av.hepatitis_b_result IS NOT NULL AND av.hepatitis_b_result <> '' THEN av.pregnancy_episode_id END)", 'den' => 'COUNT(DISTINCT av.pregnancy_episode_id)']]],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Syphilis results', 'dim' => 'av.syphilis_result', 'expr' => 'COUNT(*)', 'where' => "av.syphilis_result IS NOT NULL AND av.syphilis_result <> ''", 'labels' => ['NR' => 'Non-reactive', 'Rx' => 'Reactive (treated)', '+Ve' => 'Positive'], 'limit' => 0, 'dim_label' => 'Result'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'Hepatitis B results', 'dim' => 'av.hepatitis_b_result', 'expr' => 'COUNT(*)', 'where' => "av.hepatitis_b_result IS NOT NULL AND av.hepatitis_b_result <> ''", 'labels' => ['NR' => 'Non-reactive', 'Rx' => 'Reactive (treated)', '+Ve' => 'Positive'], 'limit' => 0, 'dim_label' => 'Result'],
        ]];

    $r['emtct_delivery'] = ['cat' => 'hiv', 'title' => 'PMTCT at delivery & infant testing', 'icon' => 'fa-hand-holding-medical',
        'desc' => 'ART, cotrimoxazole and infant prophylaxis for HIV-positive mothers, and infant DBS results.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Deliveries to HIV+ mothers', 'expr' => "SUM(dr.emtct_code_woman IN ($hivPos))", 'good' => null],
                ['label' => 'Mother on ART', 'num' => "SUM(dr.emtct_code_woman IN ($hivPos) AND dr.mother_art_code IN ('ART','ART-K'))", 'den' => "SUM(dr.emtct_code_woman IN ($hivPos))", 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Cotrimoxazole given', 'num' => "SUM(dr.emtct_code_woman IN ($hivPos) AND dr.ctx_given = 1)", 'den' => "SUM(dr.emtct_code_woman IN ($hivPos))", 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Infant ARV prophylaxis', 'num' => "SUM(dr.emtct_code_woman IN ($hivPos) AND dr.infant_arv_given = 1)", 'den' => "SUM(dr.emtct_code_woman IN ($hivPos))", 'fmt' => 'pct', 'good' => 'up']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'HIV+ mothers on ART at delivery', 'fmt' => 'pct', 'series' => [
                ['label' => 'On ART %', 'num' => "SUM(dr.emtct_code_woman IN ($hivPos) AND dr.mother_art_code IN ('ART','ART-K'))", 'den' => "SUM(dr.emtct_code_woman IN ($hivPos))"],
                ['label' => 'Infant ARV %', 'num' => "SUM(dr.emtct_code_woman IN ($hivPos) AND dr.infant_arv_given = 1)", 'den' => "SUM(dr.emtct_code_woman IN ($hivPos))", 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'pnc', 'title' => 'Infant DBS sample and result (postnatal visits)', 'dim' => "CASE WHEN pv.dbs_sample_taken = 1 THEN COALESCE(pv.dbs_test_result,'pending') ELSE 'Not taken' END", 'expr' => 'COUNT(*)', 'labels' => ['negative' => 'Negative', 'positive' => 'Positive', 'pending' => 'Pending'], 'limit' => 0, 'dim_label' => 'Status'],
            ['type' => 'metrics', 'source' => 'pnc', 'title' => 'Infant prophylaxis at postnatal visits', 'items' => [
                ['label' => 'DBS sample taken', 'num' => 'SUM(pv.dbs_sample_taken = 1)', 'den' => 'COUNT(*)'],
                ['label' => 'Septrin given', 'num' => 'SUM(pv.baby_septrin_given = 1)', 'den' => 'COUNT(*)'],
                ['label' => 'Vitamin A given', 'num' => 'SUM(pv.vitamin_a_given = 1)', 'den' => 'COUNT(*)']]],
        ]];

    // ---------------- DELIVERY ----------------
    $r['delivery_volume'] = ['cat' => 'delivery', 'title' => 'Delivery volume & timing', 'icon' => 'fa-chart-simple',
        'desc' => 'Deliveries, babies born, weekday and shift distribution for staffing decisions.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Deliveries', 'expr' => 'COUNT(*)'],
                ['label' => 'Babies born', 'expr' => 'SUM(GREATEST(COALESCE(dr.number_of_babies,1),1))'],
                ['label' => 'Multiple births', 'num' => 'SUM(dr.number_of_babies > 1)', 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Deliveries per period', 'ctype' => 'bar', 'series' => [['label' => 'Deliveries', 'expr' => 'COUNT(*)', 'color' => '#DB2777']]],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Deliveries by weekday', 'dim' => 'WEEKDAY(dr.delivery_datetime)', 'expr' => 'COUNT(*)', 'labels' => $weekdays, 'fixed' => array_keys($weekdays), 'limit' => 0, 'dim_label' => 'Weekday', 'color' => '#DB2777'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Deliveries by shift', 'dim' => "CASE WHEN HOUR(dr.delivery_datetime) BETWEEN 6 AND 13 THEN 'Morning (06–14h)' WHEN HOUR(dr.delivery_datetime) BETWEEN 14 AND 21 THEN 'Afternoon (14–22h)' ELSE 'Night (22–06h)' END", 'expr' => 'COUNT(*)', 'fixed' => ['Morning (06–14h)', 'Afternoon (14–22h)', 'Night (22–06h)'], 'limit' => 0, 'dim_label' => 'Shift', 'color' => '#DB2777'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Deliveries by hour of day', 'dim' => "LPAD(HOUR(dr.delivery_datetime), 2, '0')", 'expr' => 'COUNT(*)', 'order' => 'label', 'limit' => 0, 'dim_label' => 'Hour', 'color' => '#DB2777'],
        ]];

    $r['delivery_modes'] = ['cat' => 'delivery', 'title' => 'Mode of delivery', 'icon' => 'fa-scissors',
        'desc' => 'Mode mix, caesarean and operative delivery rates, and who is delivered by caesarean.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Deliveries', 'expr' => 'COUNT(*)'],
                ['label' => 'Caesarean rate', 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'Assisted vaginal (vacuum/forceps)', 'num' => "SUM(dr.mode_of_delivery IN ('vacuum','forceps'))", 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'Breech deliveries', 'num' => "SUM(dr.mode_of_delivery = 'breech')", 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
            ['type' => 'trendby', 'source' => 'delivery', 'title' => 'Mode of delivery over time', 'dim' => 'dr.mode_of_delivery', 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT code, description FROM delivery_modes'],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Caesarean section rate', 'fmt' => 'pct', 'series' => [['label' => 'Caesarean %', 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'color' => '#DB2777']]],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Mode of delivery: current vs previous', 'dim' => 'dr.mode_of_delivery', 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT code, description FROM delivery_modes', 'limit' => 0, 'dim_label' => 'Mode'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Caesarean rate by age group', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#DB2777'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Caesarean rate: first pregnancy vs later', 'fmt' => 'pct', 'dim' => "CASE WHEN pe.gravida = 1 THEN 'First pregnancy' WHEN pe.gravida BETWEEN 2 AND 4 THEN 'Gravida 2–4' ELSE 'Gravida 5+' END", 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'fixed' => ['First pregnancy', 'Gravida 2–4', 'Gravida 5+'], 'limit' => 0, 'dim_label' => 'Gravidity', 'color' => '#DB2777'],
        ]];

    $r['maternal_safety'] = ['cat' => 'delivery', 'title' => 'Maternal safety & obstetric care', 'icon' => 'fa-user-shield',
        'desc' => 'Maternal deaths, high-risk deliveries, third-stage management and complications.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Maternal deaths', 'expr' => 'SUM(dr.maternal_death = 1)', 'good' => 'down'],
                ['label' => 'Maternal deaths per 1,000 deliveries', 'num' => 'SUM(dr.maternal_death = 1)', 'den' => 'COUNT(*)', 'fmt' => 'dec', 'scale' => 1000, 'good' => 'down'],
                ['label' => 'High-risk deliveries', 'num' => "SUM(dr.risk_status = 'HIGH')", 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'Active 3rd-stage management', 'num' => "SUM(dr.active_management_3rd_stage IN ('Oxytocin','Misoprostol','Ergometrine'))", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'High-risk share and AMTSL coverage', 'fmt' => 'pct', 'series' => [
                ['label' => 'High risk %', 'num' => "SUM(dr.risk_status = 'HIGH')", 'den' => 'COUNT(*)', 'color' => '#EF4444'],
                ['label' => 'AMTSL %', 'num' => "SUM(dr.active_management_3rd_stage IN ('Oxytocin','Misoprostol','Ergometrine'))", 'den' => 'COUNT(*)', 'color' => '#10B981']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Maternal deaths', 'series' => [['label' => 'Maternal deaths', 'expr' => 'SUM(dr.maternal_death = 1)', 'color' => '#EF4444']]],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Third-stage management agent', 'dim' => 'dr.active_management_3rd_stage', 'expr' => 'COUNT(*)', 'limit' => 0, 'dim_label' => 'Agent'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Admission gestation', 'dim' => "CASE WHEN dr.term_preterm = 'P' THEN 'Preterm' WHEN dr.term_preterm = 'T' THEN 'Term' END", 'expr' => 'COUNT(*)', 'fixed' => ['Term', 'Preterm'], 'limit' => 0, 'dim_label' => 'Gestation'],
        ]];

    $r['delivery_age_parity'] = ['cat' => 'delivery', 'title' => 'Deliveries by age & parity', 'icon' => 'fa-people-group',
        'desc' => 'Adolescent and grand-multiparous deliveries for targeted programming.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Adolescent deliveries (<20)', 'num' => 'SUM(p.age < 20)', 'den' => 'SUM(p.age IS NOT NULL)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'First-time mothers', 'num' => 'SUM(pe.gravida = 1)', 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'Grand multipara (5+ births)', 'num' => 'SUM(pe.parity >= 5)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down']]],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Deliveries by age group', 'dim' => $age, 'expr' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#DB2777'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Deliveries by gravida', 'dim' => "CASE WHEN pe.gravida >= 6 THEN '6+' ELSE CAST(pe.gravida AS CHAR) END", 'expr' => 'COUNT(*)', 'fixed' => ['1', '2', '3', '4', '5', '6+'], 'limit' => 0, 'dim_label' => 'Gravida', 'color' => '#DB2777'],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Adolescent delivery share', 'fmt' => 'pct', 'series' => [['label' => 'Under 20 %', 'num' => 'SUM(p.age < 20)', 'den' => 'SUM(p.age IS NOT NULL)', 'color' => '#F59E0B']]],
        ]];

    // ---------------- NEWBORN ----------------
    $r['birth_outcomes'] = ['cat' => 'newborn', 'title' => 'Birth outcomes & stillbirths', 'icon' => 'fa-heart-crack',
        'desc' => 'Live births, stillbirths, neonatal deaths and defects, per baby.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'baby', 'items' => [
                ['label' => 'Babies born', 'expr' => 'COUNT(*)'],
                ['label' => 'Live births', 'expr' => "SUM($bc IN ('AL','AL_R','BDF','NND') OR $bc IS NULL)", 'good' => 'up'],
                ['label' => 'Stillbirths', 'expr' => "SUM($stillbirth)", 'good' => 'down'],
                ['label' => 'Stillbirths per 1,000 births', 'num' => "SUM($stillbirth)", 'den' => 'COUNT(*)', 'fmt' => 'dec', 'scale' => 1000, 'good' => 'down'],
                ['label' => 'Neonatal deaths (at discharge)', 'expr' => "SUM($bc = 'NND')", 'good' => 'down'],
                ['label' => 'Born with defect', 'expr' => "SUM($bc = 'BDF')", 'good' => 'down']]],
            ['type' => 'trend', 'source' => 'baby', 'title' => 'Stillbirths and neonatal deaths', 'series' => [
                ['label' => 'Stillbirths', 'expr' => "SUM($stillbirth)", 'color' => '#EF4444'], ['label' => 'Neonatal deaths', 'expr' => "SUM($bc = 'NND')", 'color' => '#7C3AED']]],
            ['type' => 'breakdown', 'source' => 'baby', 'title' => 'Condition of baby at discharge', 'dim' => $bc, 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT code, meaning FROM baby_conditions', 'limit' => 0, 'dim_label' => 'Condition', 'ctype' => 'doughnut'],
            ['type' => 'breakdown', 'source' => 'baby', 'title' => 'Stillbirths per 1,000 births by age group', 'fmt' => 'dec', 'dim' => $age, 'num' => "SUM($stillbirth)", 'den' => 'COUNT(*)', 'scale' => 1000, 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#EF4444'],
            ['type' => 'breakdown', 'source' => 'baby', 'title' => 'Stillbirths by delivery mode', 'dim' => 'dr.mode_of_delivery', 'expr' => "SUM($stillbirth)", 'labels_sql' => 'SELECT code, description FROM delivery_modes', 'limit' => 0, 'dim_label' => 'Mode', 'color' => '#EF4444'],
        ]];

    $r['birth_weight'] = ['cat' => 'newborn', 'title' => 'Birth weight', 'icon' => 'fa-weight-scale',
        'desc' => 'Low birth weight and macrosomia rates, weight bands and averages.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'items' => [
                ['label' => 'Babies weighed', 'expr' => 'COUNT(*)'],
                ['label' => 'Average birth weight (kg)', 'expr' => "AVG($bw)", 'fmt' => 'dec2'],
                ['label' => 'Low birth weight (<2.5 kg)', 'num' => "SUM($bw < 2.5)", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'Very low (<1.5 kg)', 'num' => "SUM($bw < 1.5)", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'Macrosomia (≥4.0 kg)', 'num' => "SUM($bw >= 4.0)", 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
            ['type' => 'trend', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'title' => 'Low birth weight and macrosomia', 'fmt' => 'pct', 'series' => [
                ['label' => 'LBW %', 'num' => "SUM($bw < 2.5)", 'den' => 'COUNT(*)', 'color' => '#F59E0B'], ['label' => 'Macrosomia %', 'num' => "SUM($bw >= 4.0)", 'den' => 'COUNT(*)', 'color' => '#7C3AED']]],
            ['type' => 'trend', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'title' => 'Average birth weight (kg)', 'fmt' => 'dec2', 'series' => [['label' => 'Mean kg', 'expr' => "AVG($bw)"]]],
            ['type' => 'breakdown', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'title' => 'Birth weight bands', 'dim' => "CASE WHEN $bw < 1.5 THEN '<1.5 kg' WHEN $bw < 2.5 THEN '1.5–2.4 kg' WHEN $bw < 4.0 THEN '2.5–3.9 kg' ELSE '≥4.0 kg' END", 'expr' => 'COUNT(*)', 'fixed' => ['<1.5 kg', '1.5–2.4 kg', '2.5–3.9 kg', '≥4.0 kg'], 'limit' => 0, 'dim_label' => 'Band', 'ctype' => 'doughnut'],
            ['type' => 'breakdown', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'title' => 'LBW rate by age group of mother', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM($bw < 2.5)", 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#F59E0B'],
            ['type' => 'breakdown', 'source' => 'baby', 'where' => "$bw IS NOT NULL AND " . $bb('baby_sex', 'baby_sex') . " IS NOT NULL", 'title' => 'Average birth weight by sex (kg)', 'fmt' => 'dec2', 'dim' => $bb('baby_sex', 'baby_sex'), 'expr' => "AVG($bw)", 'labels' => ['M' => 'Male', 'F' => 'Female'], 'limit' => 0, 'dim_label' => 'Sex'],
        ]];

    $r['newborn_care'] = ['cat' => 'newborn', 'title' => 'Essential newborn care', 'icon' => 'fa-heart',
        'desc' => 'Immediate newborn care coverage: skin-to-skin, early breastfeeding, prophylaxis, resuscitation and Apgar.',
        'blocks' => [
            ['type' => 'metrics', 'source' => 'baby', 'title' => 'Coverage among babies born', 'items' => [
                ['label' => 'Skin-to-skin contact', 'num' => $bool($bb('skin_to_skin', 'skin_to_skin')), 'den' => 'COUNT(*)'],
                ['label' => 'Breastfed within 1 hour', 'num' => $bool($bb('breastfed_1hr', 'breastfed_1hr')), 'den' => 'COUNT(*)'],
                ['label' => 'Tetracycline eye ointment', 'num' => $bool($bb('routine_meds_teo', 'routine_meds_teo')), 'den' => 'COUNT(*)'],
                ['label' => 'Vitamin K', 'num' => $bool($bb('routine_meds_vitk', 'routine_meds_vitk')), 'den' => 'COUNT(*)'],
                ['label' => 'Chlorhexidine cord care', 'num' => $bool($bb('routine_meds_chlorhexidine', 'routine_meds_chlorhexidine')), 'den' => 'COUNT(*)'],
                ['label' => 'Needed resuscitation', 'num' => "SUM(" . $bb('resuscitation_code', 'resuscitation_code') . " IN ('BM','BMD','SS'))", 'den' => 'COUNT(*)'],
                ['label' => 'Apgar at 5 min below 7', 'num' => 'SUM(' . $bb('apgar_5min', 'apgar_5min') . ' < 7)', 'den' => 'SUM(' . $bb('apgar_5min', 'apgar_5min') . ' IS NOT NULL)'],
            ]],
            ['type' => 'trend', 'source' => 'baby', 'title' => 'Skin-to-skin and early breastfeeding', 'fmt' => 'pct', 'series' => [
                ['label' => 'Skin-to-skin %', 'num' => $bool($bb('skin_to_skin', 'skin_to_skin')), 'den' => 'COUNT(*)'],
                ['label' => 'Breastfed ≤1h %', 'num' => $bool($bb('breastfed_1hr', 'breastfed_1hr')), 'den' => 'COUNT(*)', 'color' => '#10B981']]],
            ['type' => 'trend', 'source' => 'baby', 'title' => 'Resuscitation and low Apgar', 'fmt' => 'pct', 'series' => [
                ['label' => 'Resuscitated %', 'num' => "SUM(" . $bb('resuscitation_code', 'resuscitation_code') . " IN ('BM','BMD','SS'))", 'den' => 'COUNT(*)', 'color' => '#F59E0B'],
                ['label' => 'Apgar5 <7 %', 'num' => 'SUM(' . $bb('apgar_5min', 'apgar_5min') . ' < 7)', 'den' => 'SUM(' . $bb('apgar_5min', 'apgar_5min') . ' IS NOT NULL)', 'color' => '#EF4444']]],
            ['type' => 'breakdown', 'source' => 'baby', 'title' => 'Resuscitation outcome codes', 'dim' => $bb('resuscitation_code', 'resuscitation_code'), 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT code, meaning FROM resuscitation_codes', 'limit' => 0, 'dim_label' => 'Code'],
        ]];

    $r['gestation_preterm'] = ['cat' => 'newborn', 'title' => 'Preterm births & gestation', 'icon' => 'fa-hourglass-half',
        'desc' => 'Term versus preterm deliveries and gestation at birth.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'items' => [
                ['label' => 'Preterm deliveries', 'expr' => "SUM(dr.term_preterm = 'P')", 'good' => 'down'],
                ['label' => 'Preterm rate', 'num' => "SUM(dr.term_preterm = 'P')", 'den' => 'SUM(dr.term_preterm IS NOT NULL)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'Average gestation (weeks)', 'expr' => 'AVG(dr.gestation_weeks)', 'fmt' => 'dec']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Preterm rate', 'fmt' => 'pct', 'series' => [['label' => 'Preterm %', 'num' => "SUM(dr.term_preterm = 'P')", 'den' => 'SUM(dr.term_preterm IS NOT NULL)', 'color' => '#F59E0B']]],
            ['type' => 'breakdown', 'source' => 'delivery', 'where' => 'dr.gestation_weeks IS NOT NULL', 'title' => 'Gestation at delivery', 'dim' => "CASE WHEN dr.gestation_weeks < 28 THEN '<28 wks' WHEN dr.gestation_weeks < 32 THEN '28–31 wks' WHEN dr.gestation_weeks < 37 THEN '32–36 wks' WHEN dr.gestation_weeks < 42 THEN '37–41 wks' ELSE '42+ wks' END", 'expr' => 'COUNT(*)', 'fixed' => ['<28 wks', '28–31 wks', '32–36 wks', '37–41 wks', '42+ wks'], 'limit' => 0, 'dim_label' => 'Gestation'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Preterm rate by age group', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM(dr.term_preterm = 'P')", 'den' => 'SUM(dr.term_preterm IS NOT NULL)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#F59E0B'],
        ]];

    // ---------------- POSTNATAL ----------------
    $existsPnc = static fn(string $timing): string => "SUM(EXISTS(SELECT 1 FROM postnatal_visits x WHERE x.pregnancy_episode_id = dr.pregnancy_episode_id AND x.visit_timing = '$timing'))";
    $r['pnc_coverage'] = ['cat' => 'pnc', 'title' => 'Postnatal care coverage', 'icon' => 'fa-house-medical',
        'desc' => 'Postnatal contacts at 24 hours, 6 days and 6 weeks for mothers who delivered.',
        'blocks' => [
            ['type' => 'note', 'text' => 'Coverage cohort = mothers delivered in the selected period. Recent deliveries have not yet reached the 6-week or 6-month contact, so recent months will look lower until follow-up is due.'],
            ['type' => 'metrics', 'source' => 'delivery', 'title' => 'PNC contact coverage among delivered mothers', 'items' => [
                ['label' => 'PNC at 24 hours', 'num' => $existsPnc('24_hours'), 'den' => 'COUNT(*)'],
                ['label' => 'PNC at 6 days', 'num' => $existsPnc('6_days'), 'den' => 'COUNT(*)'],
                ['label' => 'PNC at 6 weeks', 'num' => $existsPnc('6_weeks'), 'den' => 'COUNT(*)'],
                ['label' => 'Any PNC visit', 'num' => 'SUM(EXISTS(SELECT 1 FROM postnatal_visits x WHERE x.pregnancy_episode_id = dr.pregnancy_episode_id))', 'den' => 'COUNT(*)']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'PNC 6-week coverage by delivery month', 'fmt' => 'pct', 'series' => [['label' => '6-week PNC %', 'num' => $existsPnc('6_weeks'), 'den' => 'COUNT(*)', 'color' => '#10B981']]],
            ['type' => 'trend', 'source' => 'pnc', 'title' => 'Postnatal visits', 'series' => [['label' => 'PNC visits', 'expr' => 'COUNT(*)', 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'pnc', 'title' => 'Visits by timing', 'dim' => 'pv.visit_timing', 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT timing, label FROM pnc_timings', 'limit' => 0, 'dim_label' => 'Timing', 'color' => '#10B981'],
        ]];

    $r['family_planning'] = ['cat' => 'pnc', 'title' => 'Family planning uptake', 'icon' => 'fa-people-roof',
        'desc' => 'Postpartum family planning at delivery and postnatal visits, method mix and long-acting methods.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'delivery', 'title' => 'At delivery', 'items' => [
                ['label' => 'Postpartum FP uptake', 'num' => 'SUM(dr.postpartum_fp_code IS NOT NULL AND dr.postpartum_fp_code <> 14)', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Long-acting (IUD/implant/sterilisation)', 'num' => 'SUM(dr.postpartum_fp_code IN (7,10,11,12))', 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up']]],
            ['type' => 'kpis', 'source' => 'pnc', 'title' => 'At postnatal visits', 'items' => [
                ['label' => 'Mothers on a method', 'num' => 'COUNT(DISTINCT CASE WHEN pv.fp_method_code IS NOT NULL AND pv.fp_method_code <> 14 THEN pv.pregnancy_episode_id END)', 'den' => 'COUNT(DISTINCT pv.pregnancy_episode_id)', 'fmt' => 'pct', 'good' => 'up']]],
            ['type' => 'trend', 'source' => 'delivery', 'title' => 'Postpartum FP at delivery', 'fmt' => 'pct', 'series' => [
                ['label' => 'Any method %', 'num' => 'SUM(dr.postpartum_fp_code IS NOT NULL AND dr.postpartum_fp_code <> 14)', 'den' => 'COUNT(*)', 'color' => '#10B981'],
                ['label' => 'Long-acting %', 'num' => 'SUM(dr.postpartum_fp_code IN (7,10,11,12))', 'den' => 'COUNT(*)', 'color' => '#7C3AED']]],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Method chosen at delivery', 'dim' => 'dr.postpartum_fp_code', 'expr' => 'COUNT(*)', 'where' => 'dr.postpartum_fp_code IS NOT NULL', 'labels_sql' => 'SELECT code, method_name FROM fp_methods', 'limit' => 0, 'dim_label' => 'Method', 'ctype' => 'hbar'],
            ['type' => 'breakdown', 'source' => 'pnc', 'title' => 'Method recorded at postnatal visits', 'dim' => 'pv.fp_method_code', 'expr' => 'COUNT(*)', 'where' => 'pv.fp_method_code IS NOT NULL', 'labels_sql' => 'SELECT code, method_name FROM fp_methods', 'limit' => 0, 'dim_label' => 'Method', 'ctype' => 'hbar'],
        ]];

    $r['infant_followup'] = ['cat' => 'pnc', 'title' => 'Infant feeding & postnatal findings', 'icon' => 'fa-bottle-water',
        'desc' => 'Feeding choices, baby status and referrals at postnatal visits.',
        'blocks' => [
            ['type' => 'breakdown', 'source' => 'pnc', 'title' => 'Infant feeding option', 'dim' => 'pv.infant_feeding_option', 'expr' => 'COUNT(*)', 'where' => "pv.infant_feeding_option IS NOT NULL AND pv.infant_feeding_option <> ''", 'labels' => ['EBF' => 'Exclusive breastfeeding', 'Mixed' => 'Mixed feeding', 'Formula' => 'Formula'], 'limit' => 0, 'dim_label' => 'Option', 'ctype' => 'doughnut'],
            ['type' => 'trend', 'source' => 'pnc', 'title' => 'Exclusive breastfeeding share', 'fmt' => 'pct', 'series' => [['label' => 'EBF %', 'num' => "SUM(pv.infant_feeding_option = 'EBF')", 'den' => "SUM(pv.infant_feeding_option IS NOT NULL AND pv.infant_feeding_option <> '')", 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'pnc', 'title' => 'Baby status at visit', 'dim' => 'pv.baby_status', 'expr' => 'COUNT(*)', 'where' => "pv.baby_status IS NOT NULL AND pv.baby_status <> ''", 'labels' => ['Alive' => 'Alive and well', 'Dead' => 'Deceased', 'Referred' => 'Referred'], 'limit' => 0, 'dim_label' => 'Status'],
            ['type' => 'metrics', 'source' => 'pnc', 'title' => 'Postnatal care indicators', 'items' => [
                ['label' => 'Baby referred', 'num' => 'SUM(pv.baby_referred = 1)', 'den' => 'COUNT(*)'],
                ['label' => 'Mother on cotrimoxazole (HIV+)', 'num' => 'SUM(pv.mother_ctx_given = 1)', 'den' => 'COUNT(*)']]],
        ]];

    // ---------------- CHILD ----------------
    $r['child_growth'] = ['cat' => 'child', 'title' => 'Child health visits & growth', 'icon' => 'fa-child-reaching',
        'desc' => 'Visits, children seen, feeding and average weight by age.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'child_visit', 'items' => [
                ['label' => 'Child health visits', 'expr' => 'COUNT(*)', 'good' => 'up'],
                ['label' => 'Children seen', 'expr' => 'COUNT(DISTINCT cv.baby_id)', 'good' => 'up'],
                ['label' => 'Developmental concerns noted', 'num' => "SUM(cv.developmental_concerns IS NOT NULL AND cv.developmental_concerns <> '')", 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
            ['type' => 'trend', 'source' => 'child_visit', 'title' => 'Child visits and children seen', 'series' => [
                ['label' => 'Visits', 'expr' => 'COUNT(*)', 'color' => '#0D9488'], ['label' => 'Children', 'expr' => 'COUNT(DISTINCT cv.baby_id)', 'color' => '#0EA5E9']]],
            ['type' => 'breakdown', 'source' => 'child_visit', 'title' => 'Average weight by age of child (kg)', 'fmt' => 'dec2', 'where' => 'cv.weight_kg IS NOT NULL', 'dim' => "CASE WHEN TIMESTAMPDIFF(MONTH, dr.delivery_datetime, cv.visit_date) < 2 THEN '0–1 mo' WHEN TIMESTAMPDIFF(MONTH, dr.delivery_datetime, cv.visit_date) < 6 THEN '2–5 mo' WHEN TIMESTAMPDIFF(MONTH, dr.delivery_datetime, cv.visit_date) < 12 THEN '6–11 mo' ELSE '12+ mo' END", 'expr' => 'AVG(cv.weight_kg)', 'fixed' => ['0–1 mo', '2–5 mo', '6–11 mo', '12+ mo'], 'limit' => 0, 'dim_label' => 'Age', 'color' => '#0D9488'],
            ['type' => 'breakdown', 'source' => 'child_visit', 'title' => 'Feeding status', 'dim' => 'cv.feeding_status', 'expr' => 'COUNT(*)', 'where' => "cv.feeding_status IS NOT NULL AND cv.feeding_status <> ''", 'limit' => 8, 'dim_label' => 'Feeding', 'color' => '#0D9488'],
        ]];

    $r['immunisation'] = ['cat' => 'child', 'title' => 'Immunisation performance', 'icon' => 'fa-syringe',
        'desc' => 'Doses given, missed and deferred by vaccine.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'immunization', 'items' => [
                ['label' => 'Immunisation events', 'expr' => 'COUNT(*)'],
                ['label' => 'Doses given', 'num' => "SUM(ci.status = 'given')", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Missed or not given', 'num' => "SUM(ci.status IN ('missed','not_given'))", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down'],
                ['label' => 'Children immunised', 'expr' => "COUNT(DISTINCT CASE WHEN ci.status = 'given' THEN ci.baby_id END)", 'good' => 'up']]],
            ['type' => 'trend', 'source' => 'immunization', 'title' => 'Doses given vs missed', 'series' => [
                ['label' => 'Given', 'expr' => "SUM(ci.status = 'given')", 'color' => '#10B981'], ['label' => 'Missed / not given', 'expr' => "SUM(ci.status IN ('missed','not_given'))", 'color' => '#EF4444']]],
            ['type' => 'breakdown', 'source' => 'immunization', 'title' => 'Doses given by vaccine', 'dim' => 'ci.vaccine_name', 'expr' => "SUM(ci.status = 'given')", 'limit' => 12, 'dim_label' => 'Vaccine', 'color' => '#10B981'],
            ['type' => 'breakdown', 'source' => 'immunization', 'title' => 'Missed / not given rate by vaccine', 'fmt' => 'pct', 'dim' => 'ci.vaccine_name', 'num' => "SUM(ci.status IN ('missed','not_given'))", 'den' => 'COUNT(*)', 'limit' => 12, 'dim_label' => 'Vaccine', 'color' => '#EF4444'],
            ['type' => 'breakdown', 'source' => 'immunization', 'title' => 'Status of all events', 'dim' => 'ci.status', 'expr' => 'COUNT(*)', 'limit' => 0, 'dim_label' => 'Status', 'ctype' => 'doughnut'],
        ]];

    // ---------------- REFERRAL / SCREENING / APPOINTMENTS ----------------
    $r['referrals_flow'] = ['cat' => 'referral', 'title' => 'Referral flow & completion', 'icon' => 'fa-share-from-square',
        'desc' => 'Referral volume, reasons, urgency, outcomes and time to completion.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'referral', 'items' => [
                ['label' => 'Referrals', 'expr' => 'COUNT(*)'],
                ['label' => 'Completed', 'num' => "SUM(r.status = 'completed')", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Urgent or emergency', 'num' => "SUM(r.urgency IN ('urgent','emergency'))", 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'Avg days to completion', 'expr' => "AVG(CASE WHEN r.status = 'completed' AND r.completed_date IS NOT NULL THEN DATEDIFF(r.completed_date, r.referral_date) END)", 'fmt' => 'days', 'good' => 'down'],
                ['label' => 'Not completed / declined', 'num' => "SUM(r.status IN ('not_completed','declined'))", 'den' => 'COUNT(*)', 'fmt' => 'pct', 'good' => 'down']]],
            ['type' => 'trend', 'source' => 'referral', 'title' => 'Referrals and completions', 'series' => [
                ['label' => 'Initiated', 'expr' => 'COUNT(*)', 'color' => '#F59E0B'], ['label' => 'Completed', 'expr' => "SUM(r.status = 'completed')", 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'referral', 'title' => 'Reason for referral', 'dim' => 'r.reason_category', 'expr' => 'COUNT(*)', 'labels' => ['danger_sign' => 'Danger sign', 'abnormal_screening' => 'Abnormal screening', 'specialist_review' => 'Specialist review', 'emergency' => 'Emergency', 'community_followup' => 'Community follow-up', 'other' => 'Other'], 'limit' => 0, 'dim_label' => 'Reason', 'color' => '#F59E0B'],
            ['type' => 'breakdown', 'source' => 'referral', 'title' => 'Referral status', 'dim' => 'r.status', 'expr' => 'COUNT(*)', 'limit' => 0, 'dim_label' => 'Status', 'ctype' => 'doughnut'],
            ['type' => 'breakdown', 'source' => 'referral', 'title' => 'Urgency', 'dim' => 'r.urgency', 'expr' => 'COUNT(*)', 'fixed' => ['routine', 'urgent', 'emergency'], 'limit' => 0, 'dim_label' => 'Urgency', 'color' => '#F59E0B'],
            ['type' => 'breakdown', 'source' => 'referral', 'title' => 'Top destinations', 'dim' => 'r.destination', 'expr' => 'COUNT(*)', 'limit' => 10, 'dim_label' => 'Destination', 'color' => '#F59E0B'],
        ]];

    $r['screening_yield'] = ['cat' => 'referral', 'title' => 'Screening yield', 'icon' => 'fa-vial',
        'desc' => 'Screening volume and abnormal or positive results by test.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'screening', 'items' => [
                ['label' => 'Screenings recorded', 'expr' => 'COUNT(*)'],
                ['label' => 'Abnormal / positive (of resulted)', 'num' => "SUM(sr.result_status IN ('abnormal','positive'))", 'den' => "SUM(sr.result_status <> 'pending')", 'fmt' => 'pct'],
                ['label' => 'Results pending', 'expr' => "SUM(sr.result_status = 'pending')", 'good' => 'down']]],
            ['type' => 'trend', 'source' => 'screening', 'title' => 'Screenings and abnormal findings', 'series' => [
                ['label' => 'Screenings', 'expr' => 'COUNT(*)'], ['label' => 'Abnormal / positive', 'expr' => "SUM(sr.result_status IN ('abnormal','positive'))", 'color' => '#EF4444']]],
            ['type' => 'rank', 'source' => 'screening', 'title' => 'Yield by test', 'dim' => 'sr.screening_code', 'labels_sql' => 'SELECT code, name FROM screening_catalog', 'dim_label' => 'Test', 'limit' => 15, 'cols' => [
                ['label' => 'Tests', 'expr' => 'COUNT(*)'],
                ['label' => 'Abnormal / positive %', 'num' => "SUM(sr.result_status IN ('abnormal','positive'))", 'den' => "SUM(sr.result_status <> 'pending')", 'fmt' => 'pct'],
                ['label' => 'Pending', 'expr' => "SUM(sr.result_status = 'pending')"]]],
            ['type' => 'breakdown', 'source' => 'screening', 'title' => 'Result status', 'dim' => 'sr.result_status', 'expr' => 'COUNT(*)', 'limit' => 0, 'dim_label' => 'Status', 'ctype' => 'doughnut'],
        ]];

    $r['appointments_attendance'] = ['cat' => 'referral', 'title' => 'Appointment attendance', 'icon' => 'fa-calendar-days',
        'desc' => 'Scheduled, kept and missed appointments for follow-up planning.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'appointment', 'items' => [
                ['label' => 'Appointments scheduled', 'expr' => 'COUNT(*)'],
                ['label' => 'Attendance (kept of due)', 'num' => "SUM(a.status = 'completed')", 'den' => "SUM(a.status IN ('completed','missed'))", 'fmt' => 'pct', 'good' => 'up'],
                ['label' => 'Missed', 'expr' => "SUM(a.status = 'missed')", 'good' => 'down'],
                ['label' => 'Still scheduled', 'expr' => "SUM(a.status = 'scheduled')"]]],
            ['type' => 'trend', 'source' => 'appointment', 'title' => 'Attendance rate', 'fmt' => 'pct', 'series' => [['label' => 'Attended %', 'num' => "SUM(a.status = 'completed')", 'den' => "SUM(a.status IN ('completed','missed'))", 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'appointment', 'title' => 'Attendance by appointment type', 'fmt' => 'pct', 'dim' => 'a.appointment_type', 'num' => "SUM(a.status = 'completed')", 'den' => "SUM(a.status IN ('completed','missed'))", 'labels_sql' => 'SELECT code, label FROM appointment_types', 'limit' => 0, 'dim_label' => 'Type', 'color' => '#10B981'],
            ['type' => 'breakdown', 'source' => 'appointment', 'title' => 'Missed appointments by weekday', 'dim' => 'WEEKDAY(a.scheduled_date)', 'expr' => "SUM(a.status = 'missed')", 'labels' => $weekdays, 'fixed' => array_keys($weekdays), 'limit' => 0, 'dim_label' => 'Weekday', 'color' => '#EF4444'],
            ['type' => 'breakdown', 'source' => 'appointment', 'title' => 'Appointment status', 'dim' => 'a.status', 'expr' => 'COUNT(*)', 'limit' => 0, 'dim_label' => 'Status', 'ctype' => 'doughnut'],
        ]];

    // ---------------- COMMUNITY ----------------
    $r['demographics'] = ['cat' => 'community', 'title' => 'Patient demographics', 'icon' => 'fa-users',
        'desc' => 'Who is registering: age, client category and gravidity.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'patients', 'items' => [
                ['label' => 'Patients registered', 'expr' => 'COUNT(*)'],
                ['label' => 'Average age', 'expr' => 'AVG(p.age)', 'fmt' => 'dec'],
                ['label' => 'Adolescents (<20)', 'num' => 'SUM(p.age < 20)', 'den' => 'SUM(p.age IS NOT NULL)', 'fmt' => 'pct']]],
            ['type' => 'trend', 'source' => 'patients', 'title' => 'Registrations', 'series' => [['label' => 'Patients', 'expr' => 'COUNT(*)', 'color' => '#7C3AED']]],
            ['type' => 'breakdown', 'source' => 'patients', 'title' => 'Age distribution', 'dim' => $age, 'expr' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#7C3AED'],
            ['type' => 'breakdown', 'source' => 'patients', 'title' => 'Client category', 'dim' => 'p.client_category', 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT code, name FROM client_categories', 'limit' => 0, 'dim_label' => 'Category', 'ctype' => 'doughnut'],
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Gravidity of booked pregnancies', 'dim' => "CASE WHEN pe.gravida >= 6 THEN '6+' ELSE CAST(pe.gravida AS CHAR) END", 'expr' => 'COUNT(*)', 'fixed' => ['1', '2', '3', '4', '5', '6+'], 'limit' => 0, 'dim_label' => 'Gravida', 'color' => '#7C3AED'],
        ]];

    $r['geography'] = ['cat' => 'community', 'title' => 'Catchment & geography', 'icon' => 'fa-map-location-dot',
        'desc' => 'Where mothers come from, and where outcomes and risks cluster.',
        'blocks' => [
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Pregnancies by sub-county', 'dim' => 'p.sub_county', 'expr' => 'COUNT(*)', 'limit' => 12, 'dim_label' => 'Sub-county', 'color' => '#7C3AED'],
            ['type' => 'rank', 'source' => 'episodes', 'title' => 'Villages: pregnancies and booking', 'dim' => 'p.village', 'dim_label' => 'Village', 'limit' => 20, 'color' => '#7C3AED', 'cols' => [
                ['label' => 'Pregnancies', 'expr' => 'COUNT(*)'],
                ['label' => 'Early booking %', 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)', 'fmt' => 'pct'],
                ['label' => 'Under 20 %', 'num' => 'SUM(p.age < 20)', 'den' => 'SUM(p.age IS NOT NULL)', 'fmt' => 'pct']]],
            ['type' => 'rank', 'source' => 'delivery', 'title' => 'Villages: deliveries and caesarean rate', 'dim' => 'p.village', 'dim_label' => 'Village', 'limit' => 20, 'color' => '#DB2777', 'cols' => [
                ['label' => 'Deliveries', 'expr' => 'COUNT(*)'],
                ['label' => 'Caesarean %', 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'High risk %', 'num' => "SUM(dr.risk_status = 'HIGH')", 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
            ['type' => 'rank', 'source' => 'anc', 'title' => 'Villages: ANC use and hypertension', 'dim' => 'p.village', 'dim_label' => 'Village', 'limit' => 20, 'color' => '#1E40AF', 'cols' => [
                ['label' => 'ANC visits', 'expr' => 'COUNT(*)'],
                ['label' => 'Mothers', 'expr' => 'COUNT(DISTINCT av.pregnancy_episode_id)'],
                ['label' => 'Hypertensive %', 'num' => 'SUM(av.bp_systolic >= 140 OR av.bp_diastolic >= 90)', 'den' => 'SUM(av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL)', 'fmt' => 'pct']]],
        ]];

    $r['equity_age'] = ['cat' => 'community', 'title' => 'Equity: indicators by age group', 'icon' => 'fa-scale-balanced',
        'desc' => 'Compare key indicators across age groups to find who is being left behind.',
        'blocks' => [
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Early booking rate', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group'],
            ['type' => 'breakdown', 'source' => 'anc_cycle', 'where' => "pe.status NOT IN ('aborted','transferred')", 'title' => 'ANC 4+ coverage (pregnancies due)', 'fmt' => 'pct', 'dim' => $age, 'num' => 'SUM(COALESCE(ac.visits,0) >= 4)', 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#10B981'],
            ['type' => 'breakdown', 'source' => 'anc', 'title' => 'HIV positivity', 'fmt' => 'pct', 'dim' => $age, 'num' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos) THEN av.pregnancy_episode_id END)", 'den' => "COUNT(DISTINCT CASE WHEN av.woman_hiv_code IN ($hivPos,$hivNeg) THEN av.pregnancy_episode_id END)", 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#EF4444'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Caesarean rate', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#DB2777'],
            ['type' => 'breakdown', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'title' => 'Low birth weight rate', 'fmt' => 'pct', 'dim' => $age, 'num' => "SUM($bw < 2.5)", 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#F59E0B'],
            ['type' => 'breakdown', 'source' => 'baby', 'title' => 'Stillbirths per 1,000 births', 'fmt' => 'dec', 'dim' => $age, 'num' => "SUM($stillbirth)", 'den' => 'COUNT(*)', 'scale' => 1000, 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#EF4444'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Postpartum FP uptake at delivery', 'fmt' => 'pct', 'dim' => $age, 'num' => 'SUM(dr.postpartum_fp_code IS NOT NULL AND dr.postpartum_fp_code <> 14)', 'den' => 'COUNT(*)', 'fixed' => $ageBands, 'limit' => 0, 'dim_label' => 'Age group', 'color' => '#0D9488'],
        ]];

    $r['equity_category'] = ['cat' => 'community', 'title' => 'Equity: indicators by client category', 'icon' => 'fa-globe',
        'desc' => 'National, refugee and foreign clients compared on access and outcomes.',
        'blocks' => [
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Pregnancies booked', 'dim' => 'p.client_category', 'expr' => 'COUNT(*)', 'labels_sql' => 'SELECT code, name FROM client_categories', 'limit' => 0, 'dim_label' => 'Category'],
            ['type' => 'breakdown', 'source' => 'episodes', 'title' => 'Early booking rate', 'fmt' => 'pct', 'dim' => 'p.client_category', 'num' => "SUM(pe.booking_timing = 'early')", 'den' => 'SUM(pe.booking_timing IS NOT NULL)', 'labels_sql' => 'SELECT code, name FROM client_categories', 'limit' => 0, 'dim_label' => 'Category', 'color' => '#10B981'],
            ['type' => 'breakdown', 'source' => 'delivery', 'title' => 'Caesarean rate', 'fmt' => 'pct', 'dim' => 'p.client_category', 'num' => "SUM(dr.mode_of_delivery = 'caesarean_section')", 'den' => 'COUNT(*)', 'labels_sql' => 'SELECT code, name FROM client_categories', 'limit' => 0, 'dim_label' => 'Category', 'color' => '#DB2777'],
            ['type' => 'breakdown', 'source' => 'baby', 'where' => "$bw IS NOT NULL", 'title' => 'Low birth weight rate', 'fmt' => 'pct', 'dim' => 'p.client_category', 'num' => "SUM($bw < 2.5)", 'den' => 'COUNT(*)', 'labels_sql' => 'SELECT code, name FROM client_categories', 'limit' => 0, 'dim_label' => 'Category', 'color' => '#F59E0B'],
        ]];

    // ---------------- OPERATIONS ----------------
    $r['staff_activity'] = ['cat' => 'ops', 'title' => 'Staff activity & productivity', 'icon' => 'fa-user-clock', 'roles' => $adminRoles,
        'desc' => 'Records entered per staff member and module, for workload and supervision.',
        'blocks' => [['type' => 'custom', 'run' => 'analyticsStaffActivity']]];

    $r['data_quality'] = ['cat' => 'ops', 'title' => 'Data completeness & quality', 'icon' => 'fa-clipboard-check', 'roles' => $adminRoles,
        'desc' => 'How completely key fields are recorded, overall and by staff member.',
        'blocks' => [
            ['type' => 'metrics', 'source' => 'anc', 'title' => 'ANC visit completeness', 'items' => [
                ['label' => 'Blood pressure recorded', 'num' => 'SUM(av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL)', 'den' => 'COUNT(*)'],
                ['label' => 'Weight recorded', 'num' => 'SUM(av.weight_kg IS NOT NULL)', 'den' => 'COUNT(*)'],
                ['label' => 'MUAC recorded', 'num' => 'SUM(av.muac_cm IS NOT NULL)', 'den' => 'COUNT(*)'],
                ['label' => 'Gestational age recorded', 'num' => 'SUM(av.gestational_age_weeks IS NOT NULL)', 'den' => 'COUNT(*)'],
                ['label' => 'HIV status recorded', 'num' => "SUM(av.woman_hiv_code IS NOT NULL AND av.woman_hiv_code <> '')", 'den' => 'COUNT(*)'],
                ['label' => 'Next visit date set', 'num' => 'SUM(av.next_visit_date IS NOT NULL)', 'den' => 'COUNT(*)']]],
            ['type' => 'metrics', 'source' => 'baby', 'title' => 'Delivery and newborn completeness', 'items' => [
                ['label' => 'Birth weight recorded', 'num' => "SUM($bw IS NOT NULL)", 'den' => 'COUNT(*)'],
                ['label' => 'Apgar 5 min recorded', 'num' => 'SUM(' . $bb('apgar_5min', 'apgar_5min') . ' IS NOT NULL)', 'den' => 'COUNT(*)'],
                ['label' => 'Condition at discharge recorded', 'num' => "SUM($bc IS NOT NULL AND $bc <> '')", 'den' => 'COUNT(*)'],
                ['label' => 'Mode of delivery recorded', 'num' => "SUM(dr.mode_of_delivery IS NOT NULL AND dr.mode_of_delivery <> '')", 'den' => 'COUNT(*)'],
                ['label' => 'Postpartum FP counselled', 'num' => 'SUM(dr.postpartum_fp_code IS NOT NULL)', 'den' => 'COUNT(*)']]],
            ['type' => 'rank', 'source' => 'anc', 'title' => 'ANC completeness by staff member', 'join' => ' LEFT JOIN users u ON u.id = av.recorded_by', 'dim' => "COALESCE(u.full_name, '(unknown)')", 'dim_label' => 'Staff', 'limit' => 60, 'chart' => false, 'cols' => [
                ['label' => 'Visits recorded', 'expr' => 'COUNT(*)'],
                ['label' => 'BP %', 'num' => 'SUM(av.bp_systolic IS NOT NULL AND av.bp_diastolic IS NOT NULL)', 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'Weight %', 'num' => 'SUM(av.weight_kg IS NOT NULL)', 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'HIV status %', 'num' => "SUM(av.woman_hiv_code IS NOT NULL AND av.woman_hiv_code <> '')", 'den' => 'COUNT(*)', 'fmt' => 'pct'],
                ['label' => 'MUAC %', 'num' => 'SUM(av.muac_cm IS NOT NULL)', 'den' => 'COUNT(*)', 'fmt' => 'pct']]],
        ]];

    $r['system_usage'] = ['cat' => 'ops', 'title' => 'System usage & peak load', 'icon' => 'fa-computer', 'roles' => $adminRoles,
        'desc' => 'Recorded actions, active users and busiest hours.',
        'blocks' => [
            ['type' => 'kpis', 'source' => 'logs', 'items' => [
                ['label' => 'Recorded actions', 'expr' => 'COUNT(*)'],
                ['label' => 'Active users', 'expr' => 'COUNT(DISTINCT l.user_id)'],
                ['label' => 'Actions per active user', 'num' => 'COUNT(*)', 'den' => 'COUNT(DISTINCT l.user_id)', 'fmt' => 'dec']]],
            ['type' => 'trend', 'source' => 'logs', 'title' => 'Actions and active users', 'series' => [
                ['label' => 'Actions', 'expr' => 'COUNT(*)'], ['label' => 'Active users', 'expr' => 'COUNT(DISTINCT l.user_id)', 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'logs', 'title' => 'Actions by hour of day', 'dim' => "LPAD(HOUR(l.created_at), 2, '0')", 'expr' => 'COUNT(*)', 'order' => 'label', 'limit' => 0, 'dim_label' => 'Hour'],
            ['type' => 'breakdown', 'source' => 'logs', 'title' => 'Actions by type', 'dim' => 'l.action', 'expr' => 'COUNT(*)', 'limit' => 12, 'dim_label' => 'Action'],
            ['type' => 'rank', 'source' => 'logs', 'title' => 'Most active users', 'dim' => "COALESCE(u.full_name, '(system)')", 'dim_label' => 'User', 'limit' => 20, 'cols' => [['label' => 'Actions', 'expr' => 'COUNT(*)']]],
        ]];

    $r['stock_status'] = ['cat' => 'ops', 'title' => 'Commodity stock & consumption', 'icon' => 'fa-boxes-stacked',
        'desc' => 'Current stock against reorder levels, days of cover and consumption trend.',
        'blocks' => [
            ['type' => 'custom', 'run' => 'analyticsStockStatus'],
            ['type' => 'trend', 'source' => 'commodity_tx', 'title' => 'Units issued vs received', 'series' => [
                ['label' => 'Issued', 'expr' => "-SUM(CASE WHEN ct.transaction_type = 'issue' THEN ct.quantity_delta ELSE 0 END)", 'color' => '#EF4444'],
                ['label' => 'Received', 'expr' => "SUM(CASE WHEN ct.transaction_type = 'receipt' THEN ct.quantity_delta ELSE 0 END)", 'color' => '#10B981']]],
            ['type' => 'breakdown', 'source' => 'commodity_tx', 'title' => 'Top consumed commodities (units issued)', 'dim' => 'c.name', 'expr' => "-SUM(CASE WHEN ct.transaction_type = 'issue' THEN ct.quantity_delta ELSE 0 END)", 'limit' => 10, 'dim_label' => 'Commodity', 'color' => '#EF4444'],
        ]];

    $r['workforce'] = ['cat' => 'ops', 'title' => 'Workforce capacity & workload', 'icon' => 'fa-user-nurse',
        'desc' => 'Planned versus available staff, and clinical workload per available full-time equivalent.',
        'blocks' => [
            ['type' => 'trend', 'source' => 'staffing', 'title' => 'Planned vs available FTE', 'fmt' => 'dec', 'series' => [
                ['label' => 'Planned FTE', 'expr' => 'SUM(sf.planned_fte)', 'color' => '#64748B'], ['label' => 'Available FTE', 'expr' => 'SUM(sf.available_fte)']]],
            ['type' => 'breakdown', 'source' => 'staffing', 'title' => 'Staffing fill rate by cadre', 'fmt' => 'pct', 'dim' => 'sf.cadre', 'num' => 'SUM(sf.available_fte)', 'den' => 'SUM(sf.planned_fte)', 'limit' => 0, 'dim_label' => 'Cadre', 'color' => '#1E40AF'],
            ['type' => 'custom', 'run' => 'analyticsWorkload'],
        ]];

    return $r;
}

// ---------- custom blocks ----------

function analyticsStaffActivity(PDO $pdo, array $f, string $id): array
{
    $branches = [
        ['anc_visits', 'recorded_by', 'ANC visits'], ['delivery_records', 'recorded_by', 'Deliveries'],
        ['postnatal_visits', 'recorded_by', 'PNC visits'], ['pregnancy_episodes', 'created_by', 'Pregnancy bookings'],
        ['patients', 'created_by', 'Registrations'], ['screening_results', 'recorded_by', 'Screenings'],
        ['referrals', 'recorded_by', 'Referrals'], ['child_health_visits', 'recorded_by', 'Child visits'],
        ['child_immunizations', 'recorded_by', 'Immunisations'], ['appointments', 'created_by', 'Appointments'],
    ];
    $modules = array_column($branches, 2);
    $start = $f['from'] . ' 00:00:00';
    $end = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $union = [];
    $params = [];
    foreach ($branches as [$table, $col, $label]) {
        $union[] = "SELECT $col AS uid, '$label' AS module, created_at AS ts FROM $table WHERE created_at >= ? AND created_at < ? AND $col IS NOT NULL" . ($f['staff'] ? " AND $col = ?" : '');
        array_push($params, $start, $end);
        if ($f['staff']) {
            $params[] = $f['staff'];
        }
    }
    $unionSql = implode(' UNION ALL ', $union);
    $rows = analyticsRun($pdo, "SELECT t.uid, t.module, COUNT(*) AS c FROM ($unionSql) t GROUP BY t.uid, t.module", $params);
    $users = [];
    foreach ($pdo->query('SELECT id, full_name, role, is_active FROM users')->fetchAll() as $u) {
        $users[(int) $u['id']] = $u;
    }
    $grid = [];
    foreach ($rows as $row) {
        $grid[(int) $row['uid']][$row['module']] = (int) $row['c'];
    }
    $totals = array_map('array_sum', $grid);
    arsort($totals);

    $activeAccounts = 0;
    foreach ($users as $u) {
        $activeAccounts += (int) $u['is_active'];
    }
    $totalRecords = array_sum($totals);
    $kpis = ['kind' => 'kpis', 'title' => '', 'items' => [
        ['label' => 'Records entered', 'fmt' => 'int', 'value' => number_format($totalRecords), 'sub' => '', 'delta' => null, 'cur' => ['v' => $totalRecords], 'cmp' => null],
        ['label' => 'Staff who entered data', 'fmt' => 'int', 'value' => (string) count($totals), 'sub' => 'of ' . $activeAccounts . ' active accounts', 'delta' => null, 'cur' => ['v' => count($totals)], 'cmp' => null],
        ['label' => 'Records per active staff', 'fmt' => 'dec', 'value' => count($totals) ? number_format($totalRecords / count($totals), 1) : '–', 'sub' => '', 'delta' => null, 'cur' => ['v' => null], 'cmp' => null],
    ]];

    $head = array_merge(['Staff', 'Role'], $modules, ['Total']);
    $tableRows = [];
    foreach ($totals as $uid => $total) {
        $u = $users[$uid] ?? ['full_name' => 'User #' . $uid, 'role' => ''];
        $row = [$u['full_name'], getUserRoleLabel($u['role'])];
        foreach ($modules as $m) {
            $row[] = number_format($grid[$uid][$m] ?? 0);
        }
        $row[] = number_format($total);
        $tableRows[] = $row;
    }
    $top = array_slice($totals, 0, 15, true);
    $chart = [
        'kind' => 'chart', 'id' => $id, 'title' => 'Records entered by staff (top 15)', 'note' => 'Counts records created in the period, by the person who recorded them.',
        'ctype' => 'hbar', 'stacked' => false, 'fmt' => 'int',
        'labels' => array_map(static fn($uid) => $users[$uid]['full_name'] ?? ('User #' . $uid), array_keys($top)),
        'datasets' => [['label' => 'Records', 'data' => array_values($top), 'color' => ANALYTICS_COLORS[0], 'cmp' => false]],
        'table' => ['head' => $head, 'rows' => $tableRows],
    ];

    $moduleTotals = [];
    foreach ($grid as $mods) {
        foreach ($mods as $m => $c) {
            $moduleTotals[$m] = ($moduleTotals[$m] ?? 0) + $c;
        }
    }
    arsort($moduleTotals);
    $moduleChart = [
        'kind' => 'chart', 'id' => $id . 'm', 'title' => 'Records by module', 'note' => '',
        'ctype' => 'doughnut', 'stacked' => false, 'fmt' => 'int',
        'labels' => array_keys($moduleTotals),
        'datasets' => [['label' => 'Records', 'data' => array_values($moduleTotals), 'color' => ANALYTICS_COLORS[1], 'cmp' => false]],
        'table' => ['head' => ['Module', 'Records'], 'rows' => array_map(static fn($m, $c) => [$m, number_format($c)], array_keys($moduleTotals), $moduleTotals)],
    ];

    $buckets = analyticsBuckets($f['from'], $f['to'], $f['group']);
    $bucketExpr = analyticsBucketExpr('t.ts', $f['group']);
    $perBucket = analyticsRun($pdo, "SELECT $bucketExpr AS bk, COUNT(*) AS c FROM ($unionSql) t GROUP BY bk", $params);
    $byKey = array_column($perBucket, 'c', 'bk');
    $trendData = array_map(static fn($b) => (int) ($byKey[$b['key']] ?? 0), $buckets);
    $trend = [
        'kind' => 'chart', 'id' => $id . 't', 'title' => 'Records entered over time', 'note' => '',
        'type' => 'bar', 'stacked' => false, 'fmt' => 'int', 'labels' => array_column($buckets, 'label'),
        'datasets' => [['label' => 'Records', 'data' => $trendData, 'color' => ANALYTICS_COLORS[0], 'cmp' => false]],
        'table' => ['head' => ['Period', 'Records'], 'rows' => array_map(static fn($b, $v) => [$b['label'], number_format($v)], $buckets, $trendData)],
    ];
    return [$kpis, $trend, $chart, $moduleChart];
}

function analyticsStockStatus(PDO $pdo, array $f, string $id): array
{
    $rows = $pdo->query(
        "SELECT c.name, c.unit, c.reorder_level, c.lead_time_days,
                COALESCE(SUM(t.quantity_delta), 0) AS on_hand,
                COALESCE(-SUM(CASE WHEN t.transaction_type = 'issue' AND t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND t.transaction_date <= CURDATE() THEN t.quantity_delta ELSE 0 END), 0) / 90 AS daily_use
         FROM commodities c
         LEFT JOIN commodity_transactions t ON t.commodity_id = c.id
         WHERE c.is_active = 1
         GROUP BY c.id
         ORDER BY c.name"
    )->fetchAll();
    $labels = [];
    $onHand = [];
    $reorder = [];
    $table = [];
    $out = 0;
    $low = 0;
    $risk = 0;
    foreach ($rows as $r) {
        $stock = (float) $r['on_hand'];
        $daily = (float) $r['daily_use'];
        $cover = $daily > 0 ? $stock / $daily : null;
        if ($stock <= 0) {
            $status = 'Stock-out';
            $out++;
        } elseif ($stock <= (float) $r['reorder_level']) {
            $status = 'Below reorder level';
            $low++;
        } elseif ($cover !== null && $cover < (float) $r['lead_time_days']) {
            $status = 'Will run out before resupply';
            $risk++;
        } else {
            $status = 'Adequate';
        }
        $labels[] = $r['name'];
        $onHand[] = $stock;
        $reorder[] = (float) $r['reorder_level'];
        $table[] = [$r['name'], $r['unit'], number_format($stock, 0), number_format((float) $r['reorder_level'], 0), number_format($daily, 1), $cover === null ? '–' : number_format($cover, 0) . ' d', (int) $r['lead_time_days'] . ' d', $status];
    }
    $kpi = static fn(string $label, int $v): array => ['label' => $label, 'fmt' => 'int', 'value' => (string) $v, 'sub' => '', 'delta' => null, 'cur' => ['v' => $v], 'cmp' => null];
    return [
        ['kind' => 'note', 'text' => 'Stock position is a current snapshot (not filtered by date). Daily use is the average issue rate over the last 90 days.'],
        ['kind' => 'kpis', 'title' => '', 'items' => [$kpi('Active commodities', count($rows)), $kpi('Stock-outs', $out), $kpi('Below reorder level', $low), $kpi('Run out before resupply', $risk)]],
        [
            'kind' => 'chart', 'id' => $id, 'title' => 'On hand vs reorder level', 'note' => '',
            'type' => 'bar', 'stacked' => false, 'fmt' => 'int', 'labels' => $labels,
            'datasets' => [
                ['label' => 'On hand', 'data' => $onHand, 'color' => ANALYTICS_COLORS[0], 'cmp' => false],
                ['label' => 'Reorder level', 'data' => $reorder, 'color' => ANALYTICS_COLORS[3], 'cmp' => false],
            ],
            'table' => ['head' => ['Commodity', 'Unit', 'On hand', 'Reorder level', 'Daily use', 'Days of cover', 'Lead time', 'Status'], 'rows' => $table],
        ],
    ];
}

function analyticsWorkload(PDO $pdo, array $f, string $id): array
{
    $buckets = analyticsBuckets($f['from'], $f['to'], 'month');
    $start = $f['from'];
    $end = $f['to'];
    $monthly = static function (string $sql, array $params) use ($pdo): array {
        return array_column(analyticsRun($pdo, $sql, $params), 'c', 'bk');
    };
    $fte = $monthly("SELECT DATE_FORMAT(month_start, '%Y-%m') AS bk, SUM(available_fte) AS c FROM staffing_records WHERE month_start BETWEEN ? AND ? GROUP BY bk", [$start, $end]);
    $del = $monthly("SELECT DATE_FORMAT(delivery_datetime, '%Y-%m') AS bk, COUNT(*) AS c FROM delivery_records WHERE delivery_datetime >= ? AND delivery_datetime < ? GROUP BY bk", [$start . ' 00:00:00', (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
    $anc = $monthly("SELECT DATE_FORMAT(visit_date, '%Y-%m') AS bk, COUNT(*) AS c FROM anc_visits WHERE visit_date BETWEEN ? AND ? GROUP BY bk", [$start, $end]);
    $labels = [];
    $dData = [];
    $aData = [];
    $rows = [];
    foreach ($buckets as $b) {
        $f1 = (float) ($fte[$b['key']] ?? 0);
        $d = $f1 > 0 ? (float) ($del[$b['key']] ?? 0) / $f1 : null;
        $a = $f1 > 0 ? (float) ($anc[$b['key']] ?? 0) / $f1 : null;
        $labels[] = $b['label'];
        $dData[] = $d;
        $aData[] = $a;
        $rows[] = [$b['label'], analyticsFmt($f1, 'dec'), number_format((float) ($del[$b['key']] ?? 0)), analyticsFmt($d, 'dec'), number_format((float) ($anc[$b['key']] ?? 0)), analyticsFmt($a, 'dec')];
    }
    return [[
        'kind' => 'chart', 'id' => $id, 'title' => 'Workload per available FTE (monthly)', 'note' => 'Months without a staffing record show no value. Add staffing records under Resources & Workforce.',
        'type' => 'line', 'stacked' => false, 'fmt' => 'dec', 'labels' => $labels,
        'datasets' => [
            ['label' => 'Deliveries per FTE', 'data' => $dData, 'color' => '#DB2777', 'cmp' => false],
            ['label' => 'ANC visits per FTE', 'data' => $aData, 'color' => '#1E40AF', 'cmp' => false],
        ],
        'table' => ['head' => ['Month', 'Available FTE', 'Deliveries', 'Deliveries / FTE', 'ANC visits', 'ANC visits / FTE'], 'rows' => $rows],
    ]];
}
