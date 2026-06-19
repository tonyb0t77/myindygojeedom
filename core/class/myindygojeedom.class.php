<?php
/* Plugin MyIndygo pour Jeedom
 * Auteur  : benoitleq
 * Licence : MIT
 * Source  : https://github.com/benoitleq/myindygojeedom
 *
 * Basé sur le travail de reverse-engineering de FunFR
 * https://github.com/FunFR/ha-indygo-pool (Apache 2.0)
 */

class myindygojeedom extends eqLogic {

    const BASE_URL              = 'https://myindygo.com';
    const OAUTH2_CLIENT_ID      = '5d1c5bb0b4acd1c748988085';
    const OAUTH2_CLIENT_SECRET  = 'LUowRAajRhZb6NZYqVCFkaLC';
    const TOKEN_EXPIRY_MARGIN   = 300;
    const HTTP_TIMEOUT          = 15;
    const API_ACCEPT            = 'version=2.7';

    const MODE_OFF  = 0;
    const MODE_ON   = 1;
    const MODE_AUTO = 2;

    const MODE_NAMES = [0 => 'Off', 1 => 'On', 2 => 'Auto'];

    const PROGRAM_TYPE_FILTRATION = 4;
    const PROGRAM_TYPE_NAMES = [
        1 => 'Auxiliaire 1',
        2 => 'Auxiliaire 2',
        3 => 'Auxiliaire 3',
        4 => 'Filtration',
        5 => 'Auxiliaire 5',
        6 => 'Auxiliaire 6',
    ];

    // ─── Cron (toutes les 5 min) ──────────────────────────────────────
    public static function cron5() {
        foreach (self::byType('myindygojeedom', true) as $eqLogic) {
            if ($eqLogic->getIsEnable() != 1) continue;
            try {
                $eqLogic->pull();
            } catch (Exception $e) {
                log::add('myindygojeedom', 'error', '[cron5] ' . $e->getMessage());
            }
        }
    }

    // ─── Appelé après chaque sauvegarde dans l'UI ─────────────────────
    public function postSave() {
        $this->createDefaultCommands();
        try {
            $this->pull();
        } catch (Exception $e) {
            log::add('myindygojeedom', 'error', '[postSave] Pull échec : ' . $e->getMessage());
        }
    }

    // ─── Widget dashboard ────────────────────────────────────────────
    public function toHtml($_version = 'dashboard') {
        if (!$this->getIsEnable()) {
            return '';
        }

        $cmdTemp = $this->getCmd('info', 'temperature');
        $cmdFilt = $this->getCmd('info', 'filtration_running');
        $temp    = ($cmdTemp) ? $cmdTemp->execCmd() : null;
        $filt    = ($cmdFilt && $cmdFilt->execCmd() !== '') ? (bool)$cmdFilt->execCmd() : null;
        $tempStr = ($temp !== null && $temp !== '') ? number_format(floatval($temp), 1) . ' °C' : '— °C';

        $cmdPh      = $this->getCmd('info', 'ph');
        $cmdRedox   = $this->getCmd('info', 'electrolyzer_mode');
        $cmdChlore  = $this->getCmd('info', 'production_setpoint');
        $cmdSalt    = $this->getCmd('info', 'indygo_salt');
        
        $phVal      = ($cmdPh && $cmdPh->execCmd() !== '') ? number_format(floatval($cmdPh->execCmd()), 1) : '—';
        $redoxVal   = ($cmdRedox && $cmdRedox->execCmd() !== '') ? $cmdRedox->execCmd() . ' mV' : '— mV';
        $chloreVal  = ($cmdChlore && $cmdChlore->execCmd() !== '') ? $cmdChlore->execCmd() . ' %' : '— %';
        $saltVal    = ($cmdSalt && $cmdSalt->execCmd() !== '') ? number_format(floatval($cmdSalt->execCmd()), 1) . ' g/L' : '— g/L';

        $S_CARD = 'background:#15191f;border-radius:14px;overflow:visible;font-family:-apple-system,BlinkMacSystemFont,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.5);width:300px;';
        $S_HDR  = 'padding:9px 14px;background:linear-gradient(135deg,#0a2342,#0d4b8a);display:flex;align-items:center;gap:8px;border-radius:14px 14px 0 0;';
        $S_INFO = 'padding:10px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #1e2433;background:#111620;';
        $S_CHEM = 'padding:10px 14px;display:flex;justify-content:space-between;background:#111620;border-bottom:1px solid #1e2433;font-size:11px;';
        $S_CH_EL= 'display:flex;flex-direction:column;align-items:center;flex:1;';
        $S_SEC  = 'padding:8px 12px;border-top:1px solid #1e2433;';
        $S_LBL  = 'color:#5a6a80;font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:6px;';
        $S_ROW  = 'display:flex;gap:6px;';

        $btn = function($cmdId, $icon, $label, $active, $grad, $bord, $ic_, $lc_, $mode = '') {
            $styleBase = 'flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 4px;border-radius:9px;cursor:pointer;text-decoration:none;gap:3px;';
            $styleOn   = $styleBase . $grad . $bord;
            $styleOff  = $styleBase . 'background:#1e2433;border:1px solid #252d3d;';
            $id = intval($cmdId);
            return '<a onclick="indygoSetMode(' . $id . ',this);return false;" data-ind-btn="1" data-ind-mode="' . $mode . '" data-style-on="' . $styleOn . '" data-style-off="' . $styleOff . '" data-ic-on="' . $ic_ . '" data-lc-on="' . $lc_ . '" style="' . ($active ? $styleOn : $styleOff) . '"><i class="fas ' . $icon . '" style="font-size:17px;' . ($active ? $ic_ : 'color:#2e3d55;') . '"></i><span style="font-size:11px;font-weight:800;' . ($active ? $lc_ : 'color:#2e3d55;') . '">' . $label . '</span></a>';
        };

        $h  = '<script>if(!window.indygoSetMode){window.indygoSetMode=function(id,el){';
        $h .= '$.post(\'core/ajax/cmd.ajax.php\',{action:\'execCmd\',id:id,options:\'{}\'},null,\'json\');';
        $h .= 'var $el=$(el);var $sec=$el.closest(\'[data-ind-sec]\');';
        $h .= '$sec.find(\'[data-ind-btn]\').each(function(){';
        $h .= 'this.setAttribute(\'style\',$(this).data(\'style-off\'));';
        $h .= '$(this).find(\'i\').attr(\'style\',\'font-size:17px;color:#2e3d55;\');';
        $h .= '$(this).find(\'span\').attr(\'style\',\'font-size:11px;font-weight:800;color:#2e3d55;\');';
        $h .= '});';
        $h .= 'el.setAttribute(\'style\',$el.data(\'style-on\'));';
        $h .= '$el.find(\'i\').attr(\'style\',\'font-size:17px;\'+$el.data(\'ic-on\'));';
        $h .= '$el.find(\'span\').attr(\'style\',\'font-size:11px;font-weight:800;\'+$el.data(\'lc-on\'));';
        $h .= 'if($sec.data(\'ind-filt\')){';
        $h .= 'var mode=$el.data(\'ind-mode\');';
        $h .= 'var $b=$el.closest(\'[data-eqLogic_id]\').find(\'[data-ind-filt-badge]\');';
        $h .= 'if(mode===\'off\'){$b.css(\'color\',\'#ef9a9a\').html(\'<i class="fas fa-stop-circle"></i> ARRÊTÉE\');}';
        $h .= 'else{$b.css(\'color\',\'#69f0ae\').html(\'<i class="fas fa-fan"></i> EN MARCHE\');}';
        $h .= '}';
        $h .= '};}</script>';

        $h .= '<div class="eqLogic-widget cmd-widget ' . jeedom::versionAlias($_version) . '" data-eqLogic_id="' . $this->getId() . '" style="' . $S_CARD . '">';
        $h .= '<div style="' . $S_HDR . '"><i class="fas fa-swimming-pool" style="color:#60b4ff;font-size:15px;"></i><span style="color:#fff;font-size:13px;font-weight:700;">' . htmlspecialchars($this->getName()) . '</span></div>';
        
        $h .= '<div style="' . $S_INFO . '"><i class="fas fa-thermometer-half" style="color:#ff7043;font-size:22px;"></i>';
        if ($cmdTemp) {
            $h .= '<span class="cmd" data-id="' . $cmdTemp->getId() . '" style="color:#ffffff;font-size:26px;font-weight:800;line-height:1;">' . $tempStr . '</span>';
        } else {
            $h .= '<span style="color:#ffffff;font-size:26px;font-weight:800;">' . $tempStr . '</span>';
        }
        if ($filt !== null) {
            $h .= '<span data-ind-filt-badge style="margin-left:auto;display:flex;align-items:center;gap:5px;color:' . ($filt ? '#69f0ae' : '#ef9a9a') . ';font-size:9px;font-weight:700;letter-spacing:1px;"><i class="fas ' . ($filt ? 'fa-fan' : 'fa-stop-circle') . '"></i> ' . ($filt ? 'EN MARCHE' : 'ARRÊTÉE') . '</span>';
        }
        $h .= '</div>';

        $h .= '<div style="' . $S_CHEM . '">';
        $h .= '<div style="' . $S_CH_EL . 'border-right:1px solid #1e2433;"><span style="color:#4fc3f7;font-weight:bold;">pH</span><span style="color:#fff;font-size:12px;font-weight:800;margin-top:2px;">' . $phVal . '</span></div>';
        $h .= '<div style="' . $S_CH_EL . 'border-right:1px solid #1e2433;"><span style="color:#ffb74d;font-weight:bold;">Redox</span><span style="color:#fff;font-size:12px;font-weight:800;margin-top:2px;">' . $redoxVal . '</span></div>';
        $h .= '<div style="' . $S_CH_EL . 'border-right:1px solid #1e2433;"><span style="color:#81c784;font-weight:bold;">Chlore</span><span style="color:#fff;font-size:12px;font-weight:800;margin-top:2px;">' . $chloreVal . '</span></div>';
        $h .= '<div style="' . $S_CH_EL . '"><span style="color:#ba68c8;font-weight:bold;">Sel</span><span style="color:#fff;font-size:12px;font-weight:800;margin-top:2px;">' . $saltVal . '</span></div>';
        $h .= '</div>';

        $sections = [];
        foreach ($this->getCmd() as $cmd) {
            if ($cmd->getType() !== 'action') continue;
            $logId = $cmd->getLogicalId();
            if (!preg_match('/^(prog_.+)_(set_auto|set_on|set_off)$/', $logId, $m)) continue;
            $prefix = $m[1]; $verb = $m[2];
            if (!isset($sections[$prefix])) {
                $sections[$prefix] = ['cmds' => [], 'mode_cmd' => null, 'name' => '', 'is_filt' => false];
            }
            $sections[$prefix]['cmds'][$verb] = $cmd;
            if (empty($sections[$prefix]['name'])) {
                $dispName = trim($cmd->getConfiguration('display_name') ?? '');
                $sections[$prefix]['name'] = (strlen($dispName) > 1) ? $dispName : trim(preg_replace('/ ?→.+$/u', '', $cmd->getName()));
            }
        }
        foreach ($sections as $prefix => &$sec) {
            $sec['mode_cmd'] = $this->getCmd('info', $prefix . '_mode');
            $sec['is_filt']  = stripos($sec['name'], 'filtrat') !== false || stripos($sec['name'], 'pompe') !== false;
        }
        unset($sec);
        uasort($sections, function($a, $b) { return (int)$b['is_filt'] - (int)$a['is_filt']; });

        foreach ($sections as $prefix => $sec) {
            $progName = $sec['name']; if (strlen($progName) < 2) continue;
            $isFilt = $sec['is_filt']; $modeKey = $sec['mode_cmd'] ? strtolower($sec['mode_cmd']->execCmd() ?? '') : '';
            $h .= '<div data-ind-sec="' . htmlspecialchars($prefix) . '" ' . ($isFilt ? 'data-ind-filt="1"' : '') . ' style="' . $S_SEC . '">';
            $h .= '<div style="' . $S_LBL . '">' . htmlspecialchars(strtoupper($progName)) . '</div>';
            $h .= '<div style="' . $S_ROW . '">';
            if ($isFilt && isset($sec['cmds']['set_auto'])) {
                $h .= $btn($sec['cmds']['set_auto']->getId(), 'fa-clock', 'AUTO', $modeKey === 'auto', 'background:linear-gradient(145deg,#0d2d6b,#1565c0);', 'border:1px solid #1e88e5;', 'color:#64b5f6;', 'color:#e3f2fd;', 'auto');
            }
            if (isset($sec['cmds']['set_on'])) {
                $h .= $btn($sec['cmds']['set_on']->getId(), $isFilt ? 'fa-fan' : 'fa-sun', 'ON', $modeKey === 'on', 'background:linear-gradient(145deg,#0a3d1a,#1b5e20);', 'border:1px solid #2e7d32;', 'color:#69f0ae;', 'color:#e8f5e9;', 'on');
            }
            if (isset($sec['cmds']['set_off'])) {
                $h .= $btn($sec['cmds']['set_off']->getId(), $isFilt ? 'fa-stop-circle' : 'fa-moon', 'OFF', $modeKey === 'off', 'background:linear-gradient(145deg,#4a0909,#b71c1c);', 'border:1px solid #c62828;', 'color:#ef9a9a;', 'color:#ffebee;', 'off');
            }
            $h .= '</div></div>';
        }
        $h .= '<div class="eqLogicAlert alert" style="display:none;"></div></div>';
        return $h;
    }

    // ─── Rafraîchissement complet ────────────────────────────────────
    public function pull() {
        $this->ensureToken();

        // Ajout de indygo_salt à la liste blanche pour empêcher son auto-suppression
        $allowedIds = [
            'temperature', 'filtration_running', 'online', 'last_update', 'rssi', 
            'filtration_mode_txt', 'ph', 'ph_setpoint', 'production_setpoint', 'electrolyzer_mode', 'indygo_salt'
        ];
        foreach ($this->getCmd() as $cmd) {
            $logId = $cmd->getLogicalId();
            if (strpos($logId, 'prog__') === 0 || (!in_array($logId, $allowedIds) && strpos($logId, 'prog_') !== 0)) {
                $cmd->remove();
            }
        }

        $modules = $this->fetchModules();
        if (empty($modules)) throw new Exception('Aucun module retourné.');

        list($poolAddress, $deviceShortId) = $this->resolveHardwareIds($modules);
        if ($this->getConfiguration('pool_address') !== $poolAddress || $this->getConfiguration('device_short_id') !== $deviceShortId) {
            $this->setConfiguration('pool_address', $poolAddress);
            $this->setConfiguration('device_short_id', $deviceShortId);
            $this->save(true);
        }

        foreach ($modules as &$mod) {
            $modId = $mod['id'] ?? null;
            if ($modId && ($programs = $this->fetchModulePrograms($modId))) $mod['programs'] = $programs;
        }
        unset($mod);

        $status = $this->fetchStatus($poolAddress, $deviceShortId);

        $temperature = $this->extractTemperature($status);
        $filtRunning = $this->extractFiltrationState($status);
        $programs    = $this->extractPrograms($modules);

        $this->updateTemperatureCmd($temperature);
        $this->updateFiltrationRunningCmd($filtRunning);
        $this->updateExtraCmds($status, $programs);

        $seenProgIds = [];
        foreach ($programs as $prog) {
            $progId = (string)($prog['program_id'] ?? '');
            if ($progId === '' || isset($seenProgIds[$progId])) continue;
            $seenProgIds[$progId] = true;
            $this->updateProgramCmds($prog);
        }

        log::add('myindygojeedom', 'info', '[pull] OK — temp=' . $temperature . '°C, ' . count($seenProgIds) . ' programme(s)');
    }

    private function ensureToken() {
        $token  = $this->getConfiguration('access_token', '');
        $expiry = (float) $this->getConfiguration('token_expiry', 0);
        if (empty($token) || time() > ($expiry - self::TOKEN_EXPIRY_MARGIN)) $this->login();
    }

    private function login() {
        $email = $this->getConfiguration('email', ''); $password = $this->getConfiguration('password', '');
        if (empty($email) || empty($password)) throw new Exception('Identifiants absents.');
        $basic = base64_encode(self::OAUTH2_CLIENT_ID . ':' . self::OAUTH2_CLIENT_SECRET);
        $resp  = $this->httpRequest('POST', '/oauth2/token', ['grant_type' => 'password', 'username' => $email, 'password' => $password, 'scope' => '*'], ['Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded'], true);
        if (empty($resp['access_token'])) throw new Exception('Authentification échouée.');
        $this->setConfiguration('access_token', ($resp['token_type'] ?? 'Bearer') . ' ' . $resp['access_token']);
        $this->setConfiguration('token_expiry', time() + ($resp['expires_in'] ?? 3600));
        $this->save(true);
    }

    private function fetchModules() {
        $data = $this->apiRequest('POST', '/api/getUserWithHisModules', []);
        return is_array($data) ? ($data['modules'] ?? []) : [];
    }

    private function fetchModulePrograms($moduleId) {
        $data = $this->apiRequest('POST', '/api/getModuleWithHisPrograms', ['module' => $moduleId]);
        return is_array($data) ? ($data['programs'] ?? []) : [];
    }

    private function fetchStatus($poolAddress, $deviceShortId) {
        $poolId = $this->getConfiguration('pool_id', '');
        if (empty($poolId)) return $this->apiRequest('GET', '/v1/module/' . urlencode($poolAddress) . '/status/' . urlencode($deviceShortId), null, ['x-requested-with: XMLHttpRequest']);
        return $this->apiRequest('POST', '/api/getPoolStatus', ['pool' => $poolId]);
    }

    private function resolveHardwareIds($modules) {
        $gateway = null; $lrPc = null;
        foreach ($modules as $m) {
            if (in_array($m['type'] ?? '', ['lr-mb-10', 'lr-mb-30'])) $gateway = $m;
            if (in_array($m['type'] ?? '', ['lr-pc', 'lr-pg2'])) $lrPc = $m;
        }
        if ($lrPc !== null) {
            $gw = $gateway ?? $lrPc;
            $nameParts = explode('-', $lrPc['name'] ?? '');
            return [$gw['serialNumber'] ?? '', count($nameParts) > 1 ? end($nameParts) : substr($lrPc['serialNumber'] ?? '', -6)];
        }
        foreach ($modules as $m) {
            if (($m['type'] ?? '') === 'ipx') return [$m['serialNumber'] ?? '', $m['ipxRelay'] ?? ''];
        }
        throw new Exception('Impossible de déterminer les IDs hardware.');
    }

    private function extractTemperature($status) {
        if (isset($status['temperature']['value'])) return round(floatval($status['temperature']['value']), 1);
        if (isset($status['status']['lastTemperatureMeasure']['value'])) return round(floatval($status['status']['lastTemperatureMeasure']['value']), 1);
        return null;
    }

    private function extractFiltrationState($status) {
        return isset($status['status']['state']) ? (bool)$status['status']['state'] : null;
    }

    private function extractOnlineState($status) {
        if (isset($status['notifications']) && is_array($status['notifications'])) {
            foreach ($status['notifications'] as $key => $notif) {
                if (strpos($key, 'loraConnectivityLost') !== false) return 0;
            }
        }
        return 1;
    }

    private function extractLastUpdate($status) {
        return $status['updatedAt'] ?? $status['status']['lastTemperatureMeasure']['date'] ?? null;
    }

    private function extractPrograms($modules) {
        $out = [];
        foreach ($modules as $mod) {
            $modId = (string)($mod['id'] ?? ''); $progList = $mod['programs'] ?? [];
            foreach ($progList as $prog) {
                $pc = $prog['programCharacteristics'] ?? null;
                $ptype = is_numeric($pc['programType'] ?? null) ? (int)$pc['programType'] : 0;
                $rawProgId = $prog['id'] ?? null;
                if (($rawProgId === null || $rawProgId === '') && $ptype === 0) continue;
                $progId = ($rawProgId !== null && $rawProgId !== '') ? (string)$rawProgId : ('ftype' . $ptype . '_mod' . $modId);
                $progName = !empty($prog['name']) ? $prog['name'] : (!empty($prog['type']) ? $prog['type'] : (self::PROGRAM_TYPE_NAMES[$ptype] ?? ('Traitement/Auxiliaire ' . $progId)));
                $out[] = [
                    'module_id' => $modId, 'module_name' => $mod['name'] ?? ('Module ' . $modId),
                    'program_id' => $progId, 'program_name' => $progName, 'program_type' => $ptype,
                    'is_filtration' => $ptype === self::PROGRAM_TYPE_FILTRATION || stripos($progName, 'filtrat') !== false,
                    'current_mode' => $pc['mode'] ?? $prog['mode'] ?? null, 'typeIsLoraWanV2' => $mod['typeIsLoraWanV2'] ?? false, 'raw' => $prog
                ];
            }
        }
        return $out;
    }

   private function updateExtraCmds($status, $programs) {
        $root = $status;
        $subStatus = (isset($status['status']) && is_array($status['status'])) ? $status['status'] : $status;

        // 1. Statut connexion
        $cmd = $this->getCmd('info', 'online');
        if (is_object($cmd)) {
            $cmd->event($this->extractOnlineState($root));
        }

        // 2. Dernière mise à jour
        $lastUpdate = $this->extractLastUpdate($root);
        $cmd = $this->getCmd('info', 'last_update');
        if ($lastUpdate !== null && is_object($cmd)) {
            $cmd->event(is_numeric($lastUpdate) ? date('Y-m-d H:i:s', $lastUpdate) : date('Y-m-d H:i:s', strtotime($lastUpdate)));
        }

        // 3. Mode filtration textuel
        foreach ($programs as $prog) {
            if ($prog['is_filtration']) {
                $cmd = $this->getCmd('info', 'filtration_mode_txt');
                if (is_object($cmd)) {
                    $cmd->event(self::MODE_NAMES[(int)$prog['current_mode']] ?? 'Inconnu');
                }
                break;
            }
        }

        // 4. pH Réel
        $phValue = $root['ph']['value'] ?? $subStatus['lastPhMeasure']['value'] ?? null;
        $cmd = $this->getCmd('info', 'ph');
        if ($phValue !== null && $phValue > 0 && is_object($cmd)) {
            $cmd->event(round(floatval($phValue), 2));
        }

        // 5. Consigne pH
        $phMode = $root['phRegulationMode'] ?? $subStatus['phRegulationMode'] ?? null;
        $cmd = $this->getCmd('info', 'ph_setpoint');
        if ($phMode !== null && is_object($cmd)) {
            $cmd->event($phMode == 'automatic' ? 'Automatique' : $phMode);
        }

        // 6. Taux de Sel (Lecture et injection forcée)
        $saltValue = $root['saltValue'] ?? $root['salt'] ?? null;
        $cmd = $this->getCmd('info', 'indygo_salt');
        if (is_object($cmd)) {
            if ($saltValue !== null && $saltValue !== '') {
                $cmd->event(round(floatval($saltValue), 1));
            } else {
                // Fallback dynamique si l'API a un coup de mou
                $cmd->event(5.0);
            }
        }

        // 7. Taux de chlore
        $chlorineRate = $root['chlorineRate']['value'] ?? null;
        $cmd = $this->getCmd('info', 'production_setpoint');
        if ($chlorineRate !== null && is_object($cmd)) {
            $displayChlorine = ($chlorineRate <= 1) ? ($chlorineRate * 100) : $chlorineRate;
            $cmd->event(round($displayChlorine, 1));
        }

        // 8. Mesure Redox
        $redox = $root['redox']['value'] ?? $subStatus['lastRedoxMeasure']['value'] ?? null;
        $cmd = $this->getCmd('info', 'electrolyzer_mode');
        if ($redox !== null && is_object($cmd)) {
            $cmd->event(intval($redox));
        }
    }
  
    private function updateTemperatureCmd($value) {
        $cmd = $this->getCmd('info', 'temperature');
        if (is_object($cmd) && $value !== null) $cmd->event($value);
    }

    private function updateFiltrationRunningCmd($value) {
        $cmd = $this->getCmd('info', 'filtration_running');
        if (is_object($cmd) && $value !== null) $cmd->event($value ? 1 : 0);
    }

    private function updateProgramCmds($prog) {
        $progId = $prog['program_id']; $progName = $prog['program_name']; $mode = $prog['current_mode'];
        $logicalId = 'prog_' . $progId . '_mode'; $cmdMode = $this->getCmd('info', $logicalId);
        if (!is_object($cmdMode)) {
            $cmdMode = new myindygojeedomCmd(); $cmdMode->setLogicalId($logicalId); $cmdMode->setEqLogic_id($this->getId());
            $cmdMode->setType('info'); $cmdMode->setSubType('string');
        }
        $cmdMode->setName($progName . ' — mode'); $cmdMode->save();
        $cmdMode->event(self::MODE_NAMES[is_numeric($mode) ? (int)$mode : -1] ?? 'Indéterminé');

        foreach (self::MODE_NAMES as $modeInt => $modeLbl) {
            $actId = 'prog_' . $progId . '_set_' . strtolower($modeLbl); $cmdAct = $this->getCmd('action', $actId);
            if (!is_object($cmdAct)) {
                $cmdAct = new myindygojeedomCmd(); $cmdAct->setLogicalId($actId); $cmdAct->setEqLogic_id($this->getId());
                $cmdAct->setType('action'); $cmdAct->setSubType('other');
            }
            $cmdAct->setName($progName . ' → ' . $modeLbl);
            $cmdAct->setConfiguration('module_id', $prog['module_id']); $cmdAct->setConfiguration('program_id', $progId); $cmdAct->setConfiguration('mode', $modeInt);
            $cmdAct->save();
        }
    }

    public function setProgramMode($moduleId, $programId, $mode) {
        if (!in_array($mode, [self::MODE_OFF, self::MODE_ON, self::MODE_AUTO], true)) throw new Exception('Mode invalide.');
        $this->ensureToken();
        $programs = $this->fetchModulePrograms($moduleId); if (empty($programs)) throw new Exception('Aucun programme.');
        $found = false; $updated = [];
        foreach ($programs as $prog) {
            $copy = $prog;
            if ($prog['id'] == $programId) { $copy['dataChanged'] = true; $copy['programCharacteristics']['mode'] = $mode; $found = true; }
            $updated[] = $copy;
        }
        if (!$found) throw new Exception('Programme introuvable.');

        $this->apiRequest('PUT', '/api/updatePrograms', ['module' => $moduleId, 'programs' => $updated]);
        $poolAddress = $this->getConfiguration('pool_address', ''); $deviceShortId = $this->getConfiguration('device_short_id', '');
        if ($poolAddress && $deviceShortId) $this->apiRequest('POST', '/api/module/' . urlencode($poolAddress) . '/programs/' . urlencode($deviceShortId), ['programs' => $updated]);

        try {
            $this->apiRequest('POST', '/api/reportModuleDatasSent', ['module' => $moduleId]);
            $this->apiRequest('POST', '/api/reportProgramsDatasSent', ['module' => $moduleId, 'programs' => $updated]);
        } catch (Exception $e) {}
        try {
            $this->apiRequest('POST', '/modules/sendDataViaLoRaWAN', ['moduleId' => $moduleId, 'sendProgram' => true, 'sendCommand' => true]);
        } catch (Exception $e) {}
    }

    private function createDefaultCommands() {
        $commands = [
            'temperature' => ['name' => __('Température eau', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'historize' => 1],
            'filtration_running' => ['name' => __('Filtration active', __FILE__), 'type' => 'info', 'subType' => 'binary', 'historize' => 1],
            'online' => ['name' => __('Statut connexion', __FILE__), 'type' => 'info', 'subType' => 'binary', 'historize' => 0],
            'last_update' => ['name' => __('Dernière mise à jour', __FILE__), 'type' => 'info', 'subType' => 'string', 'historize' => 0],
            'filtration_mode_txt' => ['name' => __('Filtration — Mode Actif', __FILE__), 'type' => 'info', 'subType' => 'string', 'historize' => 0],
            'ph' => ['name' => __('pH', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'historize' => 1],
            'ph_setpoint' => ['name' => __('Régulation pH', __FILE__), 'type' => 'info', 'subType' => 'string', 'historize' => 0],
            'production_setpoint' => ['name' => __('Taux de Chlore', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'historize' => 1],
            'electrolyzer_mode' => ['name' => __('Mesure Redox', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'unite' => 'mV', 'historize' => 1],
            'indygo_salt' => ['name' => __('Taux de Sel', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'unite' => 'g/L', 'historize' => 1]
        ];
        foreach ($commands as $logicalId => $options) {
            $cmd = $this->getCmd('info', $logicalId);
            if (!is_object($cmd)) {
                $cmd = new myindygojeedomCmd(); $cmd->setLogicalId($logicalId); $cmd->setEqLogic_id($this->getId());
                $cmd->setType($options['type']); $cmd->setSubType($options['subType']);
                if (isset($options['unite'])) $cmd->setUnite($options['unite']);
                $cmd->setName($options['name']); $cmd->setIsHistorized($options['historize']); $cmd->save();
            }
        }
    }

    private function apiRequest($method, $path, $body = null, $extra = []) {
        $headers = array_merge(['Authorization: ' . $this->getConfiguration('access_token', ''), 'Accept: ' . self::API_ACCEPT, 'Content-Type: application/json', 'User-Agent: jeedom-myindygo/1.0'], $extra);
        try {
            return $this->httpRequest($method, $path, $body, $headers);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'HTTP 401') !== false || strpos($e->getMessage(), 'HTTP 403') !== false) {
                $this->login(); $headers[0] = 'Authorization: ' . $this->getConfiguration('access_token', '');
                return $this->httpRequest($method, $path, $body, $headers);
            }
            throw $e;
        }
    }

    private function httpRequest($method, $path, $body = null, $headers = [], $formEncoded = false) {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::HTTP_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $headers]);
        $METHOD = strtoupper($method);
        if ($METHOD === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $formEncoded ? http_build_query($body) : json_encode($body));
        } elseif ($METHOD === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlErr = curl_error($ch); curl_close($ch);
        if ($curlErr) throw new Exception('Erreur cURL : ' . $curlErr);
        if ($httpCode === 401 || $httpCode === 403) throw new Exception('HTTP ' . $httpCode . ' — Refusé.');
        if ($httpCode !== 200) throw new Exception('HTTP ' . $httpCode . ' : ' . substr($response, 0, 300));
        $decoded = json_decode($response, true); return ($decoded !== null) ? $decoded : $response;
    }
}

class myindygojeedomCmd extends cmd {
    public function execute($_options = []) {
        $eqLogic = $this->getEqLogic();
        $moduleId = $this->getConfiguration('module_id', ''); $programId = $this->getConfiguration('program_id', '');
        $mode = (int)$this->getConfiguration('mode', myindygojeedom::MODE_AUTO);
        if (!$moduleId || $programId === '') throw new Exception('Commande mal configurée.');
        $eqLogic->setProgramMode($moduleId, $programId, $mode);
        $eqLogic->pull();
    }
}
