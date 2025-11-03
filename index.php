<?php
// ==============================================================================
// 1. PHP-Konfiguration und Daten-Handler
// ==============================================================================

// Dateiname für die Speicherung der Konfiguration (muss beschreibbar sein!)
define( 'DATA_FILE', 'bgp_config_data.json' );

/**
 * Liest die Konfiguration aus der JSON-Datei.
 * @return array
 */
function get_config_data() {
    // Standardwerte, falls keine Datei existiert
    $default_data = [
        'config_data' => [
            'asn' => '', 
            'router-id' => '', 
            'bgpq-args' => '', 
            'peeringdb-url' => '',
            'irr-server' => 'rr.ntt.net', 
            'rtr-server' => 'rtr.koeppel.it:3323', 
            'accept-default' => false, 
            'default-route' => false, 
            'keep-filtered' => false,
        ],
        'statics' => [],
        'prefixes' => [],
        'peers' => [],
    ];

    if ( file_exists( DATA_FILE ) ) {
        $content = file_get_contents( DATA_FILE );
        $data = json_decode( $content, true );
        // Merge mit Defaults, um fehlende Schlüssel zu vermeiden
        return array_merge( $default_data, $data );
    }
    return $default_data;
}

/**
 * Speichert die gesamte Konfiguration in der JSON-Datei.
 * @param array $data
 * @return bool
 */
function save_config_data( $data ) {
    $json_data = json_encode( $data, JSON_PRETTY_PRINT );
    // Versucht, die Datei zu schreiben
    return file_put_contents( DATA_FILE, $json_data );
}

/**
 * Holt alle aktuellen Daten, verarbeitet das Formular und gibt die finalen Daten zurück.
 * @return array
 */
function handle_request() {
    $data = get_config_data();
    $statics = $data['statics'];
    $prefixes = $data['prefixes'];
    $peers = $data['peers'];
    $config = $data['config_data'];
    $redirect_needed = false;
    
    // --- Daten aus GET (zum Entfernen) ---
    if ( isset( $_GET['remove_static'] ) ) {
        $prefix_to_remove = filter_input( INPUT_GET, 'remove_static', FILTER_SANITIZE_STRING );
        if ( isset( $statics[ $prefix_to_remove ] ) ) {
            unset( $statics[ $prefix_to_remove ] );
            $data['statics'] = $statics;
            save_config_data( $data );
            $redirect_needed = true;
        }
    }
    if ( isset( $_GET['remove_prefix'] ) ) {
        $index_to_remove = filter_input( INPUT_GET, 'remove_prefix', FILTER_VALIDATE_INT );
        if ( isset( $prefixes[ $index_to_remove ] ) ) {
            array_splice( $prefixes, $index_to_remove, 1 );
            $data['prefixes'] = $prefixes;
            save_config_data( $data );
            $redirect_needed = true;
        }
    }

    // --- Daten aus POST (Formular-Submit) ---
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        
        // 1. Basis-Konfiguration speichern
        $config = [
            'asn' => filter_input( INPUT_POST, 'asn', FILTER_SANITIZE_STRING ),
            'router-id' => filter_input( INPUT_POST, 'router-id', FILTER_SANITIZE_STRING ),
            'bgpq-args' => filter_input( INPUT_POST, 'bgpq-args', FILTER_SANITIZE_STRING ),
            'peeringdb-url' => filter_input( INPUT_POST, 'peeringdb-url', FILTER_SANITIZE_URL ),
            'irr-server' => filter_input( INPUT_POST, 'irr-server', FILTER_SANITIZE_STRING ), 
            'rtr-server' => filter_input( INPUT_POST, 'rtr-server', FILTER_SANITIZE_STRING ),
            'accept-default' => isset( $_POST['accept-default'] ),
            'default-route' => isset( $_POST['default-route'] ),
            'keep-filtered' => isset( $_POST['keep-filtered'] ),
        ];
        
        // 2. Statics hinzufügen
        if ( isset( $_POST['add_static'] ) ) {
            $prefix = filter_input( INPUT_POST, 'static-prefix', FILTER_SANITIZE_STRING );
            $nexthop = filter_input( INPUT_POST, 'static-nexthop', FILTER_SANITIZE_STRING );
            if ( ! empty( $prefix ) && ! empty( $nexthop ) ) {
                $statics[ $prefix ] = $nexthop;
            }
        }

        // 3. Prefixes hinzufügen
        if ( isset( $_POST['add_prefix'] ) ) {
            $prefix = filter_input( INPUT_POST, 'prefix-input', FILTER_SANITIZE_STRING );
            if ( ! empty( $prefix ) && ! in_array( $prefix, $prefixes ) ) {
                $prefixes[] = $prefix;
            }
        }
        
        // 4. Peers aus JSON lesen und speichern
        if ( isset( $_POST['peers_json'] ) && ! empty( $_POST['peers_json'] ) ) {
            $decoded_peers = json_decode( stripslashes( $_POST['peers_json'] ), true );
            if ( is_array( $decoded_peers ) ) {
                $peers = $decoded_peers;
            }
        }
        
        // 5. Beispiel laden (mit rtr.koeppel.it:3323)
        if ( isset( $_POST['load_example'] ) ) {
            $statics = [
                "23.178.72.1/32" => "23.178.72.85",
                "2602:f96d:200::1/128" => "2602:f96d:200:13::1",
            ];
            $prefixes = ["2a0a:6044:b540::/44", "2a0f:6284:2000::/44"];
            $peers = [
                "HYEHOST-FMT-V6" => [
                    'asn' => 47272, 'listen6' => "2602:f96d:200:13::1", 'multihop' => true, 'template' => "upstream",
                    'enforce_peer_nexthop' => false, 'enforce_first_as' => false, 'neighbors' => ["2602:f96d:200::1"]
                ],
            ];
            $config = [
                'asn' => '216401', 'router-id' => '23.178.72.85', 'bgpq-args' => '-S AFRINIC,APNIC,ARIN,LACNIC,RIPE',
                'peeringdb-url' => 'https://pdb-cache.47272.net/api', 
                'irr-server' => 'rr.ntt.net', 
                'rtr-server' => 'rtr.koeppel.it:3323', 
                'accept-default' => true, 'default-route' => false, 'keep-filtered' => true,
            ];
        }

        // Änderungen speichern und umleiten
        $data['config_data'] = $config;
        $data['statics'] = $statics;
        $data['prefixes'] = $prefixes;
        $data['peers'] = $peers;
        save_config_data( $data );
        $redirect_needed = true;
    }
    
    if ($redirect_needed) {
        // Reduziert die URL, um doppelte Submits und GET-Parameter zu entfernen
        header( 'Location: ' . strtok($_SERVER["REQUEST_URI"], '?') );
        exit;
    }
    
    return [
        'config' => $config,
        'statics' => $statics,
        'prefixes' => $prefixes,
        'peers' => $peers
    ];
}

// ==============================================================================
// 2. BGPConfigGenerator Klasse (Logik)
// ==============================================================================

class BGPConfigGenerator {
    private $config = [];
    private $statics = [];
    private $prefixes = [];
    private $peers = [];

    public function __construct(array $configData, array $statics, array $prefixes, array $peers) {
        $this->config = $configData;
        $this->statics = $statics;
        $this->prefixes = $prefixes;
        $this->peers = $peers;
    }

    public function generateYamlConfig(): string {
        $yaml = '';
        $asn = $this->config['asn'];
        $asn_output = !empty($asn) ? $asn : 'null';

        // --- Basic Settings ---
        $yaml .= "asn: {$asn_output}\n";
        $yaml .= "router-id: {$this->config['router-id']}\n";
        $yaml .= "bgpq-args: {$this->config['bgpq-args']}\n";
        $yaml .= "irr-server: {$this->config['irr-server']}\n"; 
        $yaml .= "rtr-server: {$this->config['rtr-server']}\n"; 
        $yaml .= "peeringdb-url: {$this->config['peeringdb-url']}\n";
        $yaml .= "accept-default: " . ($this->config['accept-default'] ? 'true' : 'false') . "\n";
        $yaml .= "default-route: " . ($this->config['default-route'] ? 'true' : 'false') . "\n";
        $yaml .= "keep-filtered: " . ($this->config['keep-filtered'] ? 'true' : 'false') . "\n";
        $yaml .= "\n";

        // ... (Rest der YAML-Generierung bleibt gleich) ...
        // --- Kernel Statics ---
        $yaml .= "kernel:\n";
        $yaml .= "  statics:\n";
        if (empty($this->statics)) {
            $yaml .= "    {}\n";
        } else {
            foreach ($this->statics as $prefix => $nexthop) {
                $yaml .= "    \"{$prefix}\": \"{$nexthop}\"\n";
            }
        }
        $yaml .= "\n";

        // --- Prefixes ---
        $yaml .= "prefixes:\n";
        if (empty($this->prefixes)) {
             $yaml .= "    []\n";
        } else {
            foreach ($this->prefixes as $prefix) {
                $yaml .= "  - {$prefix}\n";
            }
        }
        $yaml .= "\n";
        
        // --- Templates ---
        $yaml .= "templates:\n";
        
        $yaml .= "  upstream:\n";
        $yaml .= "    allow-local-as: true\n";
        $yaml .= "    import-limit-violation: warn\n";
        $yaml .= "    announce: [  \"{$asn_output}:0:15\" ]\n";
        $yaml .= "    remove-all-communities: {$asn_output}\n";
        $yaml .= "    local-pref: 80\n";
        $yaml .= "    add-on-import: [ \"{$asn_output}:0:12\" ]\n";
        $yaml .= "\n";

        $yaml .= "  routeserver:\n";
        $yaml .= "    filter-transit-asns: true\n";
        $yaml .= "    auto-import-limits: true\n";
        $yaml .= "    enforce-peer-nexthop: false\n";
        $yaml .= "    enforce-first-as: false\n";
        $yaml .= "    announce: [ \"{$asn_output}:0:15\" ]\n";
        $yaml .= "    remove-all-communities: {$asn_output}\n";
        $yaml .= "    local-pref: 90\n";
        $yaml .= "    add-on-import: [ \"{$asn_output}:0:13\" ]\n";
        $yaml .= "\n";

        $yaml .= "  peer:\n";
        $yaml .= "    filter-irr: true\n";
        $yaml .= "    filter-transit-asns: true\n";
        $yaml .= "    auto-import-limits: true\n";
        $yaml .= "    auto-as-set: true\n";
        $yaml .= "    announce: [ \"{$asn_output}:0:15\" ]\n";
        $yaml .= "    remove-all-communities: {$asn_output}\n";
        $yaml .= "    local-pref: 100\n";
        $yaml .= "    add-on-import: [ \"{$asn_output}:0:14\" ]\n";
        $yaml .= "\n";

        $yaml .= "  downstream:\n";
        $yaml .= "    filter-irr: true\n";
        $yaml .= "    allow-blackhole-community: true\n";
        $yaml .= "    filter-transit-asns: true\n";
        $yaml .= "    auto-import-limits: true\n";
        $yaml .= "    auto-as-set: true\n";
        $yaml .= "    announce: [ \"{$asn_output}:0:15\" ]\n";
        $yaml .= "    announce-default: true\n";
        $yaml .= "    remove-all-communities: {$asn_output}\n";
        $yaml .= "    local-pref: 200\n";
        $yaml .= "    add-on-import: [ \"{$asn_output}:0:15\" ]\n";
        $yaml .= "\n";
        
        // --- Peers ---
        $yaml .= "peers:\n";
        if (empty($this->peers)) {
            $yaml .= "    {}\n";
        } else {
            foreach ($this->peers as $name => $config) {
                // Name muss für YAML sicher sein
                $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name); 
                
                $yaml .= "  {$safe_name}:\n";
                $yaml .= "    asn: {$config['asn']}\n";
                $yaml .= "    listen6: {$config['listen6']}\n";
                $yaml .= "    multihop: " . (isset($config['multihop']) && $config['multihop'] ? 'true' : 'false') . "\n";
                $yaml .= "    template: {$config['template']}\n";
                $yaml .= "    enforce-peer-nexthop: " . (isset($config['enforce_peer_nexthop']) && $config['enforce_peer_nexthop'] ? 'true' : 'false') . "\n";
                $yaml .= "    enforce-first-as: " . (isset($config['enforce_first_as']) && $config['enforce_first_as'] ? 'true' : 'false') . "\n";
                $yaml .= "    neighbors:\n";
                foreach ($config['neighbors'] as $neighbor) {
                    $yaml .= "      - {$neighbor}\n";
                }
            }
        }
        return $yaml;
    }
}

// --- Haupt-Ausführung ---
$current_data = handle_request();
$config = $current_data['config'];
$statics = $current_data['statics'];
$prefixes = $current_data['prefixes'];
$peers = $current_data['peers'];

// Generierung für die initiale Ausgabe
$generator = new BGPConfigGenerator( $config, $statics, $prefixes, $peers );
$yamlOutput = $generator->generateYamlConfig();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGP YAML Config Generator | ip6.ee</title>
    <link href="./style.css?v=3" rel="stylesheet">
</head>
<body>
    
    <header class="site-header">
        <div class="header-content-wrapper">
            <div class="logo">
                <a href="/">
                    <img 
                        src="https://ip6.ee/wp-content/uploads/2025/08/logo.png" 
                        alt="ip6.ee Logo" 
                        style="height: 40px; display: block;"
                    >
                </a>
            </div>
            <nav class="main-nav">
                <ul>
                    <li><a href="/">Home</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <div class="container">
            <div class="panel">
                <h1>BGP Config Generator</h1>
                
                <form method="POST" action="index.php">
                    
                    <h2>Basic Settings</h2>
                    <div class="form-group">
                        <label for="asn">ASN:</label>
                        <input type="number" name="asn" id="asn" value="<?php echo htmlspecialchars($config['asn']); ?>" placeholder="e.g., 216401" oninput="generateConfig()">
                    </div>
                    <div class="form-group">
                        <label for="router-id">Router ID:</label>
                        <input type="text" name="router-id" id="router-id" value="<?php echo htmlspecialchars($config['router-id']); ?>" placeholder="e.g., 23.178.72.85" oninput="generateConfig()">
                    </div>
                    <div class="form-group">
                        <label for="bgpq-args">BGPQ Args:</label>
                        <input type="text" name="bgpq-args" id="bgpq-args" value="<?php echo htmlspecialchars($config['bgpq-args']); ?>" placeholder="e.g., -S AFRINIC,APNIC,ARIN,LACNIC,RIPE" oninput="generateConfig()">
                    </div>
                    <div class="form-group">
                        <label for="irr-server">IRR Server:</label>
                        <input type="text" name="irr-server" id="irr-server" value="<?php echo htmlspecialchars($config['irr-server']); ?>" placeholder="e.g., rr.ntt.net" oninput="generateConfig()">
                    </div>
                    <div class="form-group">
                        <label for="rtr-server">RTR Server:</label>
                        <input type="text" name="rtr-server" id="rtr-server" value="<?php echo htmlspecialchars($config['rtr-server']); ?>" placeholder="e.g., rtr.koeppel.it:3323" oninput="generateConfig()">
                    </div>
                    <div class="form-group">
                        <label for="peeringdb-url">PeeringDB URL:</label>
                        <input type="text" name="peeringdb-url" id="peeringdb-url" value="<?php echo htmlspecialchars($config['peeringdb-url']); ?>" placeholder="" oninput="generateConfig()">
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="accept-default" id="accept-default" <?php echo $config['accept-default'] ? 'checked' : ''; ?> onchange="generateConfig()">
                        <label for="accept-default">Accept Default</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="default-route" id="default-route" <?php echo $config['default-route'] ? 'checked' : ''; ?> onchange="generateConfig()">
                        <label for="default-route">Default Route</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="keep-filtered" id="keep-filtered" <?php echo $config['keep-filtered'] ? 'checked' : ''; ?> onchange="generateConfig()">
                        <label for="keep-filtered">Keep Filtered</label>
                    </div>

                    <hr style="margin-top: 20px; margin-bottom: 20px;">
                    
                    <h2>Kernel Statics</h2>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                        <input type="text" name="static-prefix" id="static-prefix" placeholder="Prefix (e.g., 23.178.72.1/32)">
                        <input type="text" name="static-nexthop" id="static-nexthop" placeholder="Next Hop (e.g., 23.178.72.85)">
                        <button class="btn btn-secondary" type="submit" name="add_static">Add</button>
                    </div>
                    <div id="statics-list" class="item-list">
                        <?php 
                        if ( empty( $statics ) ) {
                            echo '<div class="item"><span>No Statics added.</span></div>';
                        }
                        foreach ( $statics as $prefix => $nexthop ) {
                            $remove_link = 'index.php?remove_static=' . urlencode( $prefix );
                            echo '<div class="item">';
                            echo '<span>"' . htmlspecialchars( $prefix ) . '": "' . htmlspecialchars( $nexthop ) . '"</span>';
                            echo '<a class="btn btn-secondary btn-small" href="' . htmlspecialchars( $remove_link ) . '">Remove</a>';
                            echo '</div>';
                        }
                        ?>
                    </div>

                    <hr style="margin-top: 20px; margin-bottom: 20px;">

                    <h2>Announce Prefixes</h2>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                        <input type="text" name="prefix-input" id="prefix-input" placeholder="e.g., 2a0a:6044:b540::/44">
                        <button class="btn btn-secondary" type="submit" name="add_prefix">Add</button>
                    </div>
                    <div id="prefixes-list" class="item-list">
                        <?php 
                        if ( empty( $prefixes ) ) {
                            echo '<div class="item"><span>No Prefixes added.</span></div>';
                        }
                        foreach ( $prefixes as $index => $prefix ) {
                            $remove_link = 'index.php?remove_prefix=' . $index;
                            echo '<div class="item">';
                            echo '<span>' . htmlspecialchars( $prefix ) . '</span>';
                            echo '<a class="btn btn-secondary btn-small" href="' . htmlspecialchars( $remove_link ) . '">Remove</a>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <hr style="margin-top: 20px; margin-bottom: 20px;">

                    <h2>Peers</h2>
                    <div class="form-group"><label for="peer-name">Peer Name:</label><input type="text" id="peer-name" placeholder="e.g., HYEHOST-FMT-V6"></div>
                    <div class="form-group"><label for="peer-asn">Peer ASN:</label><input type="number" id="peer-asn" placeholder="e.g., 47272"></div>
                    <div class="form-group"><label for="peer-listen6">Listen IPv6:</label><input type="text" id="peer-listen6" placeholder="e.g., 2602:f96d:200:13::1"></div>
                    <div class="form-group"><label for="peer-template">Template:</label><input type="text" id="peer-template" placeholder="upstream, routeserver, peer, downstream"></div>
                    
                    <div class="checkbox-group"><input type="checkbox" id="peer-multihop"><label for="peer-multihop">Multihop</label></div>
                    <div class="checkbox-group"><input type="checkbox" id="peer-enforce-nexthop"><label for="peer-enforce-nexthop">Enforce Peer Nexthop</label></div>
                    <div class="checkbox-group"><input type="checkbox" id="peer-enforce-first-as"><label for="peer-enforce-first-as">Enforce First AS</label></div>

                    <div class="form-group"><label for="peer-neighbors">Neighbors (Comma-separated):</label><input type="text" id="peer-neighbors" placeholder="e.g., 2602:f96d:200::1, 2001:504:125:e0::2"></div>
                    
                    <button class="btn btn-primary" type="button" onclick="addPeer()">Add Peer to List</button>

                    <div id="peers-list" class="item-list"></div>

                    <input type="hidden" name="peers_json" id="peers_json" value='<?php echo json_encode( $peers ); ?>'>

                    <hr style="margin-top: 20px; margin-bottom: 20px;">

                    <div class="button-group">
                        <button class="btn btn-secondary" type="submit" name="load_example">Load Example</button>
                        <button class="btn btn-primary" type="submit">Save Configuration</button>
                    </div>
                </form>
            </div>

            <div class="panel output-area">
                <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem;">
                    <h1 style="margin: 0;">Generated Config</h1>
                    <button class="btn btn-primary" onclick="copyToClipboard()">Copy to Clipboard</button>
                </div>
                <textarea id="output" readonly><?php echo htmlspecialchars( $yamlOutput ); ?></textarea>
            </div>
            </div>
    </main>

    <footer class="site-footer">
        <div class="footer-content-wrapper">
            <p>&copy; <?php echo date('Y'); ?> ip6.ee. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
        // --- INITIALISIERUNG: PHP-Daten in JS-Variablen übertragen ---
        
        let statics = <?php echo json_encode( $statics ); ?>;
        let prefixes = <?php echo json_encode( $prefixes ); ?>;
        
        let peers = {};
        const peersJsonInput = document.getElementById('peers_json');
        
        if (peersJsonInput.value) {
            try {
                // PHP-kodierte Peers dekodieren
                peers = JSON.parse(peersJsonInput.value); 
            } catch (e) {
                console.error("Error parsing peers from PHP:", e);
            }
        }

        // --- HILFSFUNKTIONEN FÜR LISTEN (NUR FÜR DIE ANZEIGE IM JS) ---

        function renderPeers() {
            const list = document.getElementById('peers-list');
            list.innerHTML = '';
            
            if (Object.keys(peers).length === 0) {
                 list.innerHTML = '<div class="item"><span>No Peers added.</span></div>';
                 peersJsonInput.value = '';
                 return;
            }

            for (const [name, config] of Object.entries(peers)) {
                const item = document.createElement('div');
                item.className = 'item';
                item.innerHTML = `
                    <span>
                        <strong>${name}</strong> 
                        <small>(ASN: ${config.asn}, Template: ${config.template})</small>
                    </span>
                    <button class="btn btn-secondary btn-small" type="button" onclick="removePeer('${name}')">Remove</button>
                `;
                list.appendChild(item);
            }
            
            // JSON-String für den PHP-Submit aktualisieren
            peersJsonInput.value = JSON.stringify(peers);
        }

        function addPeer() {
            const name = document.getElementById('peer-name').value.trim();
            const asn = document.getElementById('peer-asn').value.trim();
            const listen6 = document.getElementById('peer-listen6').value.trim();
            const template = document.getElementById('peer-template').value.trim();
            const multihop = document.getElementById('peer-multihop').checked;
            const enforceNexthop = document.getElementById('peer-enforce-nexthop').checked;
            const enforceFirstAs = document.getElementById('peer-enforce-first-as').checked;
            const neighbors = document.getElementById('peer-neighbors').value.split(',').map(n => n.trim()).filter(n => n);

            if (name && asn && listen6 && template && neighbors.length > 0 && !peers[name]) {
                peers[name] = {
                    asn: parseInt(asn), listen6, multihop, template,
                    enforce_peer_nexthop: enforceNexthop, enforce_first_as: enforceFirstAs,      
                    neighbors
                };
                // Felder zurücksetzen
                document.getElementById('peer-name').value = '';
                document.getElementById('peer-asn').value = '';
                document.getElementById('peer-listen6').value = '';
                document.getElementById('peer-template').value = '';
                document.getElementById('peer-multihop').checked = false;
                document.getElementById('peer-enforce-nexthop').checked = false;
                document.getElementById('peer-enforce-first-as').checked = false;
                document.getElementById('peer-neighbors').value = '';

                renderPeers();
                generateConfig(); // Konfigurations-Update
            } else if (peers[name]) {
                 alert(`Peer "${name}" already exists.`);
            } else {
                 alert("Please fill in all peer fields.");
            }
        }

        function removePeer(name) {
            delete peers[name];
            renderPeers();
            generateConfig(); // Konfigurations-Update
        }
        
        // --- HAUPT GENERIERUNGSFUNKTION FÜR LIVE-VORSCHAU ---
        function generateConfig() {
            // Live-Werte aus DOM holen
            const asn = document.getElementById('asn').value || '';
            const routerId = document.getElementById('router-id').value || '';
            const bgpqArgs = document.getElementById('bgpq-args').value || '';
            const peeringdbUrl = document.getElementById('peeringdb-url').value || '';
            // Server-Werte abrufen
            const irrServer = document.getElementById('irr-server').value || 'rr.ntt.net';
            const rtrServer = document.getElementById('rtr-server').value || 'rtr.koeppel.it:3323'; 
            // ---
            const acceptDefault = document.querySelector('[name="accept-default"]').checked;
            const defaultRoute = document.querySelector('[name="default-route"]').checked;
            const keepFiltered = document.querySelector('[name="keep-filtered"]').checked;
            
            // Listen verwenden die JS-Variablen (aktueller Stand, inkl. Peers)
            const currentStatics = statics;
            const currentPrefixes = prefixes;
            const currentPeers = peers;
            
            const asnOutput = asn || 'null';

            let yaml = '';
            
            // --- Basic Settings (Live) ---
            yaml += `asn: ${asnOutput}\n`;
            yaml += `router-id: ${routerId}\n`;
            yaml += `bgpq-args: ${bgpqArgs}\n`;
            // Server-Werte verwenden
            yaml += `irr-server: ${irrServer}\n`;
            yaml += `rtr-server: ${rtrServer}\n`;
            // ---
            yaml += `peeringdb-url: ${peeringdbUrl}\n`;
            yaml += `accept-default: ${acceptDefault ? 'true' : 'false'}\n`;
            yaml += `default-route: ${defaultRoute ? 'true' : 'false'}\n`;
            yaml += `keep-filtered: ${keepFiltered ? 'true' : 'false'}\n`;
            yaml += "\n";

            // ... (Rest der YAML-Generierung bleibt gleich) ...
            // --- Kernel Statics (Gespeicherte Daten) ---
            yaml += "kernel:\n";
            yaml += "  statics:\n";
            if (Object.keys(currentStatics).length === 0) {
                yaml += "    {}\n";
            } else {
                for (const [prefix, nexthop] of Object.entries(currentStatics)) {
                    yaml += `    "${prefix}": "${nexthop}"\n`;
                }
            }
            yaml += "\n";

            // --- Prefixes (Gespeicherte Daten) ---
            yaml += "prefixes:\n";
            if (currentPrefixes.length === 0) {
                yaml += "    []\n";
            } else {
                currentPrefixes.forEach(prefix => {
                    yaml += `  - ${prefix}\n`;
                });
            }
            yaml += "\n";

            // --- Templates ---
            yaml += "templates:\n";
            
            yaml += "  upstream:\n";
            yaml += "    allow-local-as: true\n";
            yaml += "    import-limit-violation: warn\n";
            yaml += `    announce: [  "${asnOutput}:0:15" ]\n`;
            yaml += `    remove-all-communities: ${asnOutput}\n`;
            yaml += "    local-pref: 80\n";
            yaml += `    add-on-import: [ "${asnOutput}:0:12" ]\n`;
            yaml += "\n";

            yaml += "  routeserver:\n";
            yaml += "    filter-transit-asns: true\n";
            yaml += "    auto-import-limits: true\n";
            yaml += "    enforce-peer-nexthop: false\n";
            yaml += "    enforce-first-as: false\n";
            yaml += `    announce: [ "${asnOutput}:0:15" ]\n`;
            yaml += `    remove-all-communities: ${asnOutput}\n`;
            yaml += "    local-pref: 90\n`;
            yaml += `    add-on-import: [ "${asnOutput}:0:13" ]\n`;
            yaml += "\n";

            yaml += "  peer:\n";
            yaml += "    filter-irr: true\n";
            yaml += "    filter-transit-asns: true\n";
            yaml += "    auto-import-limits: true\n";
            yaml += "    auto-as-set: true\n";
            yaml += `    announce: [ "${asnOutput}:0:15" ]\n`;
            yaml += `    remove-all-communities: ${asnOutput}\n`;
            yaml += "    local-pref: 100\n";
            yaml += `    add-on-import: [ "${asnOutput}:0:14" ]\n`;
            yaml += "\n";

            yaml += "  downstream:\n";
            yaml += "    filter-irr: true\n";
            yaml += "    allow-blackhole-community: true\n";
            yaml += "    filter-transit-asns: true\n";
            yaml += "    auto-import-limits: true\n";
            yaml += "    auto-as-set: true\n";
            yaml += `    announce: [ "${asnOutput}:0:15" ]\n`;
            yaml += "    announce-default: true\n";
            yaml += `    remove-all-communities: ${asnOutput}\n`;
            yaml += "    local-pref: 200\n";
            yaml += `    add-on-import: [ "${asnOutput}:0:15" ]\n`;
            yaml += "\n";
            
            // --- Peers (Live-Daten) ---
            yaml += "peers:\n";
            if (Object.keys(currentPeers).length === 0) {
                yaml += "    {}\n";
            } else {
                for (const [name, config] of Object.entries(currentPeers)) {
                    const safeName = name.replace(/[^a-zA-Z0-9_-]/g, ''); 
                    
                    yaml += `  ${safeName}:\n`;
                    yaml += `    asn: ${config.asn}\n`;
                    yaml += `    listen6: ${config.listen6}\n`;
                    yaml += `    multihop: ${config.multihop ? 'true' : 'false'}\n`;
                    yaml += `    template: ${config.template}\n`;
                    yaml += `    enforce-peer-nexthop: ${config.enforce_peer_nexthop ? 'true' : 'false'}\n`;
                    yaml += `    enforce-first-as: ${config.enforce_first_as ? 'true' : 'false'}\n`;
                    yaml += "    neighbors:\n";
                    config.neighbors.forEach(neighbor => {
                        yaml += `      - ${neighbor}\n`;
                    });
                }
            }

            document.getElementById('output').value = yaml;
        }

        function copyToClipboard() {
            const output = document.getElementById('output');
            output.select();
            document.execCommand('copy');
            alert('Config copied to clipboard!');
        }


        // Beim Laden der Seite ausführen
        document.addEventListener('DOMContentLoaded', () => {
            renderPeers();
            generateConfig();
        });
    </script>
</body>
</html>
