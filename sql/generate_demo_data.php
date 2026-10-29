<?php
// TEST DATA ONLY. Fills an EMPTY database (schema + seed.sql loaded) with a large,
// coherent synthetic dataset so analytics can be tried at realistic volume.
// Usage: DB_HOST=127.0.0.1 DB_NAME=x DB_USER=u DB_PASS=p php sql/generate_demo_data.php --confirm [--patients=6000]
// Never run this against a live database: it refuses when patients already exist.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$opts = getopt('', ['confirm', 'patients::', 'seed::']);
if (!isset($opts['confirm'])) {
    exit("Add --confirm to generate synthetic TEST data into the configured database.\n");
}
$patientsTarget = max(50, (int) ($opts['patients'] ?? 6000));
mt_srand((int) ($opts['seed'] ?? 42));

$host = getenv('DB_HOST') ?: '127.0.0.1';
$pdo = new PDO('mysql:host=' . $host . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ((int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn() > 0) {
    exit("Refusing: patients table is not empty.\n");
}

function pick(array $weighted)
{
    $total = array_sum($weighted);
    $r = mt_rand() / mt_getrandmax() * $total;
    foreach ($weighted as $k => $w) {
        if (($r -= $w) <= 0) {
            return $k;
        }
    }
    return array_key_last($weighted);
}
function chance(float $p): bool { return mt_rand() / mt_getrandmax() < $p; }
function between(int $a, int $b): int { return mt_rand($a, $b); }
function dec(float $a, float $b, int $d = 1): float { return round($a + (mt_rand() / mt_getrandmax()) * ($b - $a), $d); }

$pdo->beginTransaction();
$today = new DateTimeImmutable('today');
$monthBase = $today->modify('first day of this month');
$start = $today->modify('-24 months');
$hash = password_hash('Staff123!', PASSWORD_DEFAULT);

// Staff
$roles = ['midwife' => 18, 'nurse' => 12, 'doctor' => 6, 'receptionist' => 6, 'community_health_worker' => 4, 'manager' => 2, 'staff_admin' => 1];
$ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, job_title, is_active, last_login) VALUES (?,?,?,?,?,1,?)');
$first = ['Grace', 'Mary', 'Sarah', 'Ruth', 'Agnes', 'Esther', 'Joan', 'Betty', 'Rose', 'Juliet', 'Peter', 'John', 'Paul', 'David', 'Moses', 'Isaac', 'Samuel', 'Brian'];
$last = ['Nakato', 'Namutebi', 'Nabukenya', 'Kato', 'Ssemakula', 'Mukasa', 'Nalubega', 'Namuli', 'Lubega', 'Kizza', 'Ssali', 'Nambi', 'Okello', 'Tumusiime'];
$staff = [];
$n = 0;
foreach ($roles as $role => $count) {
    for ($i = 0; $i < $count; $i++) {
        $n++;
        $name = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
        $ins->execute([sprintf('staff%02d', $n), $hash, $name, $role, ucfirst(str_replace('_', ' ', $role)), $today->modify('-' . between(0, 6) . ' days')->format('Y-m-d H:i:s')]);
        if (in_array($role, ['midwife', 'nurse', 'doctor', 'community_health_worker'], true)) {
            $staff[] = (int) $pdo->lastInsertId();
        }
    }
}
$allStaff = array_column($pdo->query('SELECT id FROM users')->fetchAll(), 'id');

// Commodities
$commodities = [['MISO', 'Misoprostol 200mcg', 'tablet', 400], ['OXY', 'Oxytocin 10IU', 'ampoule', 300], ['IFA', 'Iron/Folic acid', 'tablet', 5000], ['SP', 'Sulfadoxine-pyrimethamine', 'tablet', 1500], ['LLIN', 'Insecticide nets', 'net', 200], ['MGSO4', 'Magnesium sulphate', 'ampoule', 80], ['TTV', 'Tetanus vaccine', 'dose', 250], ['IMPL', 'Implant', 'unit', 40]];
$cIns = $pdo->prepare('INSERT INTO commodities (code, name, unit, reorder_level, lead_time_days, is_active) VALUES (?,?,?,?,14,1)');
$tIns = $pdo->prepare('INSERT INTO commodity_transactions (commodity_id, transaction_date, transaction_type, quantity_delta, recorded_by) VALUES (?,?,?,?,?)');
foreach ($commodities as $c) {
    $cIns->execute([$c[0], $c[1], $c[2], $c[3]]);
    $cid = (int) $pdo->lastInsertId();
    $stockTarget = $c[3] * 4;
    for ($m = 24; $m >= 0; $m--) {
        $d = $monthBase->modify("-$m months");
        $tIns->execute([$cid, $d->format('Y-m-01'), 'receipt', $stockTarget + between(0, (int) ($c[3] / 2)), $staff[0]]);
        for ($k = 0; $k < 6; $k++) {
            $tIns->execute([$cid, $d->format('Y-m-') . sprintf('%02d', between(1, 27)), 'issue', -between((int) ($c[3] / 12), (int) ($c[3] / 4)), $staff[array_rand($staff)]]);
        }
    }
    if ($c[0] === 'MGSO4' || $c[0] === 'IMPL') {
        $tIns->execute([$cid, $today->format('Y-m-d'), 'adjustment', -$stockTarget * 3, $staff[0]]);
    }
}

// Staffing
$sIns = $pdo->prepare('INSERT INTO staffing_records (month_start, cadre, planned_fte, available_fte, available_hours, source, recorded_by) VALUES (?,?,?,?,?,?,?)');
for ($m = 24; $m >= 0; $m--) {
    $d = $monthBase->modify("-$m months")->format('Y-m-01');
    foreach (['Midwife' => 12, 'Nurse' => 8, 'Doctor' => 4] as $cadre => $planned) {
        $avail = max(2, $planned - between(0, 3));
        $sIns->execute([$d, $cadre, $planned, $avail, $avail * 160, 'Synthetic', $staff[0]]);
    }
}

$subs = ['Lubaga Division' => ['Nsambya', 'Kabowa', 'Lubaga Hill', 'Namirembe', 'Mengo'], 'Rubaga North' => ['Kasubi', 'Nalukolongo', 'Ndeeba', 'Busega'], 'Makindye' => ['Kibuye', 'Bunga', 'Nsambya Central'], 'Nateete' => ['Kyengera', 'Lungujja', 'Kawaala']];
$pIns = $pdo->prepare('INSERT INTO patients (surname, first_name, age, client_category, phone, district, sub_county, parish, village, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$eIns = $pdo->prepare('INSERT INTO pregnancy_episodes (patient_id, client_number, gravida, parity, lmp, edd, booking_ga_weeks, booking_timing, status, closed_date, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$aIns = $pdo->prepare('INSERT INTO anc_visits (pregnancy_episode_id, serial_no, visit_number, visit_date, gestational_age_weeks, weight_kg, muac_cm, muac_color, bp_systolic, bp_diastolic, woman_hiv_code, partner_result_code, syphilis_result, hepatitis_b_result, llin_given, mebendazole_status, iron_folate_tablets, emtct_risk, tt_dose_received, iptp_dose, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$dIns = $pdo->prepare('INSERT INTO delivery_records (pregnancy_episode_id, admission_no, ipd_no, admission_datetime, gestation_weeks, term_preterm, emtct_code_woman, mother_art_code, syphilis_result, hepatitis_b_result, mode_of_delivery, delivery_datetime, active_management_3rd_stage, baby_sex, baby_weight_kg, apgar_1min, apgar_5min, skin_to_skin, breastfed_1hr, routine_meds_teo, routine_meds_vitk, routine_meds_chlorhexidine, risk_status, infant_arv_given, postpartum_fp_code, baby_condition_at_discharge, number_of_babies, maternal_death, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$bIns = $pdo->prepare('INSERT INTO babies (delivery_record_id, birth_order, baby_sex, baby_weight_kg, apgar_1min, apgar_5min, skin_to_skin, breastfed_1hr, routine_meds_teo, routine_meds_vitk, routine_meds_chlorhexidine, condition_at_discharge) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$pnIns = $pdo->prepare('INSERT INTO postnatal_visits (pregnancy_episode_id, serial_no, visit_timing, visit_date, fp_method_code, mother_emtct_code, baby_status, baby_age_days, baby_weight_kg, infant_feeding_option, baby_id, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
$rIns = $pdo->prepare('INSERT INTO referrals (patient_id, pregnancy_episode_id, referral_date, reason_category, reason, urgency, destination, status, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)');
$scIns = $pdo->prepare('INSERT INTO screening_results (patient_id, pregnancy_episode_id, screening_code, performed_at, result_status, recorded_by) VALUES (?,?,?,?,?,?)');
$apIns = $pdo->prepare('INSERT INTO appointments (patient_id, pregnancy_episode_id, appointment_type, scheduled_date, status, created_by) VALUES (?,?,?,?,?,?)');
$cvIns = $pdo->prepare('INSERT INTO child_health_visits (baby_id, visit_date, weight_kg, length_cm, feeding_status, developmental_concerns, recorded_by) VALUES (?,?,?,?,?,?,?)');
$imIns = $pdo->prepare('INSERT INTO child_immunizations (baby_id, vaccine_name, dose_label, event_date, status, recorded_by) VALUES (?,?,?,?,?,?)');
$lIns = $pdo->prepare('INSERT INTO system_logs (user_id, action, table_name, record_id, created_at) VALUES (?,?,?,?,?)');

$hivPos = ['TRR', 'TRR+', 'TRRK'];
$hivNeg = ['TR', 'TR+', 'TRK', 'TRV'];
$vaccines = [['BCG', ''], ['OPV', '0'], ['OPV', '1'], ['Penta', '1'], ['PCV', '1'], ['Rota', '1'], ['Penta', '2'], ['PCV', '2'], ['Measles', '1']];
$modes = ['normal_vaginal' => 66, 'caesarean_section' => 26, 'vacuum' => 4, 'forceps' => 1, 'breech' => 2, 'other' => 1];
$subKeys = array_keys($subs);
$serial = 0;
$admission = 0;
$pnSerial = 0;
$totalSeconds = $today->getTimestamp() - $start->getTimestamp();

for ($i = 1; $i <= $patientsTarget; $i++) {
    // Growth trend: registrations drift upward across the 24 months.
    $u = sqrt(mt_rand() / mt_getrandmax());
    $created = (new DateTimeImmutable('@' . (int) ($start->getTimestamp() + $u * $totalSeconds)))->setTimezone($today->getTimezone());
    $sub = $subKeys[array_rand($subKeys)];
    $village = $subs[$sub][array_rand($subs[$sub])];
    $age = (int) max(14, min(46, round(24 + (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 12)));
    $staffId = $staff[array_rand($staff)];
    $createdAt = $created->setTime(between(7, 17), between(0, 59))->format('Y-m-d H:i:s');
    $pIns->execute([$last[array_rand($last)], $first[array_rand($first)], $age, pick(['N' => 90, 'R' => 7, 'F' => 3]), '07' . between(10000000, 99999999), 'Kampala', $sub, $sub, $village, $staffId, $createdAt, $createdAt]);
    $pid = (int) $pdo->lastInsertId();

    $bookingGa = pick([8 => 8, 10 => 14, 12 => 14, 16 => 20, 20 => 20, 24 => 14, 28 => 8, 32 => 2]) + between(0, 1);
    $lmp = $created->modify('-' . ($bookingGa * 7) . ' days');
    $edd = $lmp->modify('+280 days');
    $status = 'active';
    $closed = null;
    $roll = mt_rand() / mt_getrandmax();
    $delivers = false;
    if ($edd < $today) {
        if ($roll < 0.04) { $status = 'aborted'; $closed = $created->modify('+' . between(5, 40) . ' days'); if ($closed > $today) { $closed = $today; } }
        elseif ($roll < 0.07) { $status = 'transferred'; $closed = $created->modify('+' . between(10, 90) . ' days'); if ($closed > $today) { $closed = $today; } }
        else { $status = 'delivered'; $delivers = true; }
    }
    $parity = between(0, 4);
    $eIns->execute([$pid, sprintf('CN-%07d', $i), $parity + 1 + (chance(0.1) ? 1 : 0), $parity, $lmp->format('Y-m-d'), $edd->format('Y-m-d'), $bookingGa, $bookingGa <= 12 ? 'early' : 'late', $status, $closed ? $closed->format('Y-m-d') : null, $staffId, $createdAt]);
    $eid = (int) $pdo->lastInsertId();

    // ANC contacts: later booking and distance from today both limit the number attended.
    $maxVisits = $status === 'aborted' ? 1 : ($status === 'transferred' ? 2 : 8);
    $planned = min($maxVisits, pick([1 => 8, 2 => 12, 3 => 16, 4 => 24, 5 => 16, 6 => 12, 7 => 7, 8 => 5]));
    if ($bookingGa > 24) { $planned = min($planned, between(1, 4)); }
    $hivStatus = chance(0.08) ? 'pos' : 'neg';
    $syph = chance(0.02) ? pick(['Rx' => 3, '+Ve' => 1]) : 'NR';
    $hep = chance(0.015) ? pick(['Rx' => 2, '+Ve' => 1]) : 'NR';
    $partnerRes = pick(['TR' => 40, 'TRR' => 5, '' => 55]);
    $visitDate = $created;
    $firstMuac = pick(['G' => 80, 'Y' => 15, 'R' => 3, 'ND' => 2]);
    for ($v = 1; $v <= $planned; $v++) {
        if ($v > 1) { $visitDate = $visitDate->modify('+' . between(21, 36) . ' days'); }
        if ($visitDate > $today || ($status !== 'active' && $closed && $visitDate > $closed)) { break; }
        $ga = $bookingGa + (int) round($visitDate->diff($created)->days / 7);
        $mc = $v === 1 ? $firstMuac : pick(['G' => 85, 'Y' => 12, 'R' => 3]);
        $high = chance(0.06);
        $aIns->execute([
            $eid, ++$serial, $v, $visitDate->format('Y-m-d'), min($ga, 42), dec(48, 90), $mc === 'G' ? dec(23, 32) : ($mc === 'Y' ? dec(19, 22.8) : ($mc === 'R' ? dec(16, 18.8) : null)), $mc === 'ND' ? 'ND' : $mc,
            $high ? between(140, 170) : between(100, 129), $high ? between(90, 105) : between(60, 84),
            $v <= 2 || chance(0.4) ? ($hivStatus === 'pos' ? $hivPos[array_rand($hivPos)] : $hivNeg[array_rand($hivNeg)]) : '', $v === 1 ? $partnerRes : '',
            $v <= 2 ? $syph : '', $v <= 2 ? $hep : '', $v === 1 ? (chance(0.75) ? 1 : 0) : 0, $v >= 3 && chance(0.7) ? pick(['given' => 4, 'completed' => 1]) : 'not_given',
            chance(0.85) ? between(30, 90) : 0, $hivStatus === 'pos' || $high ? 'High' : 'Low', chance(0.6) ? between(1, 5) : null,
            $ga >= 13 && chance(0.7) ? 'SP' . min(3, max(1, $v - 1)) : '', $staff[array_rand($staff)], $visitDate->format('Y-m-d H:i:s'),
        ]);
    }

    // Occasional screening and appointments.
    if (chance(0.55)) {
        $scIns->execute([$pid, $eid, pick(['HIV' => 3, 'SYPHILIS' => 3, 'HEPATITIS_B' => 2, 'HAEMOGLOBIN' => 4, 'URINALYSIS' => 3, 'FERRITIN' => 1, 'THYROID' => 1]), min($today, $created->modify('+' . between(0, 60) . ' days'))->format('Y-m-d'), pick(['normal' => 70, 'abnormal' => 15, 'positive' => 5, 'negative' => 5, 'pending' => 3, 'inconclusive' => 2]), $staff[array_rand($staff)]]);
    }
    if (chance(0.5)) {
        $d = $created->modify('+' . between(7, 90) . ' days');
        $apIns->execute([$pid, $eid, pick(['ANC' => 6, 'PNC' => 2, 'CHILD' => 1, 'OTHER' => 1]), $d->format('Y-m-d'), $d > $today ? 'scheduled' : pick(['completed' => 68, 'missed' => 25, 'cancelled' => 7]), $staffId]);
    }
    if (chance(0.09)) {
        $d = min($today, $created->modify('+' . between(5, 200) . ' days'));
        $rIns->execute([$pid, $eid, $d->format('Y-m-d'), pick(['danger_sign' => 3, 'abnormal_screening' => 3, 'specialist_review' => 3, 'emergency' => 2, 'community_followup' => 1, 'other' => 1]), 'Synthetic referral', pick(['routine' => 5, 'urgent' => 3, 'emergency' => 1]), ['Mulago', 'Kiruddu', 'Nsambya', 'Naguru'][mt_rand(0, 3)], pick(['completed' => 55, 'accepted' => 15, 'initiated' => 10, 'not_completed' => 12, 'declined' => 8]), $staff[array_rand($staff)]]);
    }

    if (!$delivers) { continue; }

    $mode = pick($modes);
    $ga = pick([36 => 5, 37 => 6, 38 => 14, 39 => 30, 40 => 30, 41 => 12, 33 => 3]);
    $dt = $edd->modify('+' . between(-14, 10) . ' days')->setTime(between(0, 23), between(0, 59));
    if ($dt > $today) { $dt = $today->setTime(6, 0); }
    $multiple = chance(0.025) ? 2 : 1;
    $deliveryHiv = $hivStatus === 'pos' ? $hivPos[array_rand($hivPos)] : $hivNeg[array_rand($hivNeg)];
    $risk = $mode === 'caesarean_section' || $hivStatus === 'pos' || chance(0.15) ? 'HIGH' : 'LOW';
    $weight = dec(1.6, 4.4, 2);
    $condition = pick(['AL' => 90, 'AL_R' => 3, 'BDF' => 1, 'FSB' => 2.5, 'MSB' => 1.5, 'NND' => 2]);
    $dIns->execute([
        $eid, ++$admission, 'IPD' . $admission, $dt->modify('-' . between(2, 20) . ' hours')->format('Y-m-d H:i:s'), $ga, $ga < 37 ? 'P' : 'T', $deliveryHiv, $hivStatus === 'pos' ? pick(['ART' => 9, 'ART-K' => 1]) : 'NA',
        $syph, $hep, $mode, $dt->format('Y-m-d H:i:s'), chance(0.95) ? pick(['Oxytocin' => 85, 'Misoprostol' => 12, 'Ergometrine' => 3]) : null, chance(0.5) ? 'M' : 'F', $weight, between(5, 9), between(7, 10),
        chance(0.8) ? 1 : 0, chance(0.85) ? 1 : 0, chance(0.9) ? 1 : 0, chance(0.9) ? 1 : 0, chance(0.6) ? 1 : 0, $risk, $hivStatus === 'pos' ? 1 : 0,
        pick([14 => 40, 12 => 20, 7 => 5, 10 => 2, 11 => 1, 1 => 12, 3 => 10, 5 => 10]), $condition, $multiple, chance(0.0035) ? 1 : 0, $staff[array_rand($staff)], $dt->format('Y-m-d H:i:s'),
    ]);
    $did = (int) $pdo->lastInsertId();
    $babyIds = [];
    for ($b = 1; $b <= $multiple; $b++) {
        $bIns->execute([$did, $b, chance(0.5) ? 'M' : 'F', $b === 1 ? $weight : dec(1.5, 3.2, 2), between(5, 9), between(7, 10), chance(0.8) ? 1 : 0, chance(0.85) ? 1 : 0, chance(0.9) ? 1 : 0, chance(0.9) ? 1 : 0, chance(0.9) ? 1 : 0, $b === 1 ? $condition : 'AL']);
        $babyIds[] = (int) $pdo->lastInsertId();
    }
    $alive = !in_array($condition, ['FSB', 'MSB'], true);

    // PNC: coverage falls at later contacts and for recent deliveries.
    foreach (['24_hours' => [1, 0.9], '6_days' => [6, 0.75], '6_weeks' => [42, 0.6], '6_months' => [180, 0.35]] as $timing => [$days, $p]) {
        $vd = $dt->modify("+$days days");
        if ($vd > $today || !chance($p)) { continue; }
        $pnIns->execute([$eid, ++$pnSerial, $timing, $vd->format('Y-m-d'), pick([14 => 35, 12 => 20, 1 => 15, 3 => 15, 5 => 10, 7 => 5]), $hivStatus === 'pos' ? $hivPos[0] : $hivNeg[0], $alive ? 'Alive' : 'Dead', $days, dec(2.8, 8, 2), pick(['EBF' => 70, 'Mixed' => 22, 'Formula' => 8]), $babyIds[0], $staff[array_rand($staff)], $vd->format('Y-m-d H:i:s')]);
    }

    // Child health visits and immunisation for live births.
    if ($alive) {
        foreach ($babyIds as $bid) {
            $count = 0;
            foreach ([14, 42, 70, 98, 180, 270] as $k => $days) {
                $vd = $dt->modify("+$days days");
                if ($vd > $today || !chance(0.75 - $k * 0.08)) { continue; }
                $count++;
                $cvIns->execute([$bid, $vd->format('Y-m-d'), dec(3, 10, 2), dec(50, 75), pick(['EBF' => 60, 'Mixed' => 25, 'Complementary' => 15]), chance(0.04) ? 'Delayed milestone' : null, $staff[array_rand($staff)]]);
            }
            foreach ($vaccines as $k => [$vac, $dose]) {
                $vd = $dt->modify('+' . ($k * 14) . ' days');
                if ($vd > $today) { break; }
                $imIns->execute([$bid, $vac, $dose, $vd->format('Y-m-d'), pick(['given' => 88, 'missed' => 8, 'not_given' => 4]), $staff[array_rand($staff)]]);
            }
        }
    }
}

// Activity log: 150 entries a day across a spread of staff, last 90 days.
for ($d = 90; $d >= 0; $d--) {
    $day = $today->modify("-$d days");
    for ($k = 0; $k < 120; $k++) {
        $lIns->execute([$allStaff[array_rand($allStaff)], pick(['CREATE_ANC' => 5, 'CREATE_PATIENT' => 2, 'UPDATE_PATIENT' => 2, 'CREATE_DELIVERY' => 1, 'CREATE_PNC' => 2, 'CREATE_REFERRAL' => 1]), 'synthetic', between(1, 5000), $day->setTime(between(7, 18), between(0, 59))->format('Y-m-d H:i:s')]);
    }
}

$pdo->commit();
foreach (['users', 'patients', 'pregnancy_episodes', 'anc_visits', 'delivery_records', 'babies', 'postnatal_visits', 'referrals', 'screening_results', 'appointments', 'child_health_visits', 'child_immunizations', 'commodity_transactions', 'system_logs'] as $t) {
    echo str_pad($t, 24) . $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . "\n";
}
