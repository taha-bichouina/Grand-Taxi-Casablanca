<?php
// Connexion à la base de données
$host = 'localhost';
$db = 'taxi_casablanca';
$user = 'root';
$pass = '';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);

// Récupérer toutes les lignes avec leurs points de trajet
$stmt = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC");
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

$linesWithPoints = [];
foreach ($lines as $line) {
    $stmtPts = $pdo->prepare("SELECT latitude, longitude FROM trajets WHERE line_id = ? ORDER BY ordre ASC");
    $stmtPts->execute([$line['id']]);
    $points = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

    // Formater les points pour JS : [[lat,lng], [lat,lng], ...]
    $formattedPoints = array_map(fn($pt) => [(float)$pt['latitude'], (float)$pt['longitude']], $points);

    $linesWithPoints[] = [
        'id' => $line['id'],
        'name' => $line['name'],
        'color' => $line['color'],
        'points' => $formattedPoints,
    ];
}

// Regrouper les lignes par point de départ
function groupLines($lines) {
    $groups = [];

    foreach ($lines as $line) {
        if (empty($line['points'])) continue;

        // Normalisation du nom de ligne et du point de départ
        $lineName = strtolower(trim($line['name']));
        $departureName = strtolower(trim(extractDepartureName($line['name'])));

        // Choix du critère de regroupement (nom de ligne > départ)
        $groupKey = $lineName ?: $departureName;
        $groupLabel = ucfirst($lineName ?: $departureName);

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'label' => $groupLabel,
                'departure_name' => extractDepartureName($line['name']),
                'lines' => []
            ];
        }

        $line['destination'] = extractDestinationName($line['name']);
        $groups[$groupKey]['lines'][] = $line;
    }

    // Tri alphabétique
    ksort($groups);

    return $groups;
}

function extractDepartureName($lineName) {
    // Logique pour extraire le nom du point de départ
    // Exemple: "Derb Sultan - Sidi Bernoussi" -> "Derb Sultan"
    $parts = explode(' - ', $lineName);
    return trim($parts[0]);
}

function extractDestinationName($lineName) {
    // Logique pour extraire le nom de la destination
    $parts = explode(' - ', $lineName);
    return count($parts) > 1 ? trim($parts[1]) : 'Destination';
}

$groupedLines = groupLines($linesWithPoints);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Carte des Grands Taxis - Casablanca</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />
  <style>
    body {
      margin: 0;
      font-family: 'Poppins', sans-serif;
      background-color: #0c1a2b;
      color: #f1f5f9;
    }
    .header {
      text-align: center;
      padding: 2rem 1rem;
      background-color: #162740;
      border-bottom: 2px solid #23344e;
    }
    h1 {
      font-size: 2rem;
      margin-bottom: 0.5rem;
      animation: fadeInDown 1s ease;
    }
    .container {
      display: flex;
      flex-wrap: wrap;
      padding: 1rem;
    }
    .sidebar {
      flex: 1;
      min-width: 320px;
      max-width: 400px;
      padding: 1rem;
      background-color: #131f36;
      border-right: 2px solid #23344e;
      animation: fadeInLeft 0.8s ease;
      max-height: 80vh;
      overflow-y: auto;
    }
    .map-container {
      flex: 3;
      padding: 1rem;
      animation: fadeIn 1.2s ease;
    }
    #map {
      width: 100%;
      height: 500px;
      border: 1px solid #29497c;
      border-radius: 10px;
    }
    .search-section h3 {
      margin-top: 0;
      font-size: 1.2rem;
      color: #66aaff;
    }
    .search-input {
      width: 100%;
      padding: 10px 15px;
      margin-bottom: 15px;
      border-radius: 8px;
      background-color: #1f2d41;
      border: 1px solid #29497c;
      color: #f1f5f9;
      transition: border-color 0.3s, box-shadow 0.3s;
      box-sizing: border-box;
    }
    .search-input:focus {
      outline: none;
      border-color: #3388ff;
      box-shadow: 0 0 0 2px rgba(51, 136, 255, 0.2);
    }
    .filter-btn {
      width: 100%;
      padding: 12px;
      margin-bottom: 15px;
      background-color: #3388ff;
      border: none;
      border-radius: 8px;
      color: white;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 14px;
    }
    .filter-btn:hover {
      background-color: #1d70d1;
      transform: translateY(-2px);
    }
    
    /* Styles pour les groupes */
    .departure-group {
      background-color: #1a2742;
      border: 1px solid #29497c;
      border-radius: 12px;
      margin-bottom: 15px;
      overflow: hidden;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
      transition: all 0.3s ease;
    }
    .departure-group:hover {
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.4);
    }
    .departure-group.hidden {
      display: none;
    }
    
    .group-header {
      background: linear-gradient(135deg, #2d4a73, #1e3a5f);
      padding: 15px 20px;
      cursor: pointer;
      border-bottom: 1px solid #29497c;
      transition: all 0.3s;
      position: relative;
    }
    .group-header:hover {
      background: linear-gradient(135deg, #3a5580, #2a476b);
    }
    .group-header::after {
      content: '▼';
      position: absolute;
      right: 20px;
      top: 50%;
      transform: translateY(-50%);
      transition: transform 0.3s;
      font-size: 12px;
      color: #66aaff;
    }
    .group-header.collapsed::after {
      transform: translateY(-50%) rotate(-90deg);
    }
    
    .group-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: #66aaff;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .group-subtitle {
      font-size: 0.85rem;
      color: #a0aec0;
      margin: 5px 0 0 0;
    }
    
    .group-content {
      max-height: 400px;
      overflow: hidden;
      transition: max-height 0.4s ease-in-out;
    }
    .group-content.collapsed {
      max-height: 0;
    }
    
    .route-item {
      background-color: #1f2d41;
      border-left: 4px solid #3388ff;
      padding: 12px 15px;
      margin: 8px 12px;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.3s;
      position: relative;
    }
    .route-item:hover {
      transform: translateX(4px);
      background-color: #23344e;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }
    .route-item.active {
      background-color: #29497c !important;
      transform: translateX(6px);
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    }
    
    .route-name {
      font-size: 0.95rem;
      font-weight: 500;
      color: #f1f5f9;
      margin-bottom: 5px;
    }
    .route-destination {
      font-size: 0.8rem;
      color: #a0aec0;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .route-destination::before {
      content: '→';
      color: #66aaff;
    }
    
    .no-results {
      text-align: center;
      padding: 30px;
      color: #a0aec0;
      font-style: italic;
    }
    
    #selectedRouteName {
      font-size: 1.2rem;
      background: linear-gradient(135deg, #1f2d41, #2a3a52);
      color: #66aaff;
      padding: 15px 20px;
      border-left: 4px solid #3388ff;
      border-radius: 8px;
      margin-bottom: 15px;
      display: none;
      animation: fadeIn 0.5s ease;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }
    
    .suggestions {
      position: absolute;
      background-color: #1f2d41;
      border: 1px solid #29497c;
      border-radius: 8px;
      max-height: 200px;
      overflow-y: auto;
      z-index: 1000;
      width: 100%;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }
    .suggestion-item {
      padding: 12px 15px;
      cursor: pointer;
      border-bottom: 1px solid #29497c;
      transition: background-color 0.3s;
    }
    .suggestion-item:hover {
      background-color: #29497c;
    }
    .suggestion-item:last-child {
      border-bottom: none;
    }
    
    @keyframes fadeIn { 
      from { opacity: 0; transform: translateY(-10px); } 
      to { opacity: 1; transform: translateY(0); } 
    }
    @keyframes fadeInLeft { 
      from { transform: translateX(-30px); opacity: 0; } 
      to { transform: translateX(0); opacity: 1; } 
    }
    @keyframes fadeInDown { 
      from { transform: translateY(-20px); opacity: 0; } 
      to { transform: translateY(0); opacity: 1; } 
    }
    
    @media (max-width: 768px) {
      .container { 
        flex-direction: column; 
      }
      .sidebar { 
        border-right: none; 
        border-bottom: 2px solid #23344e;
        max-height: none;
      }
      .group-header {
        padding: 12px 15px;
      }
      .route-item {
        margin: 6px 10px;
        padding: 10px 12px;
      }
    }
  </style>
</head>
<body>
  <div class="header">
    <h1>🚖 Carte des Grands Taxis - Casablanca</h1>
    <p>Trouvez facilement les trajets des taxis blancs regroupés par point de départ</p>
  </div>
  
  <div class="container">
    <div class="sidebar">
      <div class="search-section">
        <h3>🔍 Rechercher un trajet</h3>
        <div style="position: relative">
          <input type="text" id="startPoint" class="search-input" placeholder="Point de départ..." />
          <div id="startSuggestions" class="suggestions"></div>
        </div>
        <div style="position: relative">
          <input type="text" id="endPoint" class="search-input" placeholder="Destination..." />
          <div id="endSuggestions" class="suggestions"></div>
        </div>
        <button class="filter-btn" onclick="searchRouteFromInputs()">🔍 Rechercher</button>
        <button class="filter-btn" onclick="useCurrentLocation()">📍 Ma position</button>
        
        <div style="margin-top: 20px;">
          <input type="text" id="departureFilter" class="search-input" placeholder="Filtrer par point de départ..." />
        </div>
      </div>
      
      <div class="route-list" style="margin-top: 2rem;">
        <h3>📍 Trajets par point de départ</h3>
        <div id="groupedRoutes">
          <?php if (empty($groupedLines)): ?>
            <div class="no-results">Aucune ligne enregistrée pour le moment.</div>
          <?php else: ?>
            <?php foreach ($groupedLines as $group): ?>
              <div class="departure-group" data-departure="<?= htmlspecialchars($group['departure_name']) ?>">
                <div class="group-header" onclick="toggleGroup(this)">
                  <div class="group-title">
                    📍 <?= htmlspecialchars($group['departure_name']) ?>
                  </div>
                  <div class="group-subtitle">
                    <?= count($group['lines']) ?> ligne<?= count($group['lines']) > 1 ? 's' : '' ?> disponible<?= count($group['lines']) > 1 ? 's' : '' ?>
                  </div>
                </div>
                <div class="group-content">
                  <?php foreach ($group['lines'] as $line): ?>
                    <div class="route-item" 
                         onclick="zoomToLine(<?= $line['id'] ?>)" 
                         style="border-left-color: <?= htmlspecialchars($line['color']) ?>"
                         data-line-id="<?= $line['id'] ?>">
                      <div class="route-name"><?= htmlspecialchars($line['name']) ?></div>
                      <div class="route-destination"><?= htmlspecialchars($line['destination']) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    
    <div class="map-container">
      <div id="selectedRouteName"></div>
      <button onclick="resetView()" class="filter-btn" style="margin-bottom: 15px;">↩️ Réinitialiser la carte</button>
      <div id="map"></div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
  <script>
    const MAPBOX_TOKEN = "pk.eyJ1IjoidGFoYWJpY2hvIiwiYSI6ImNtZDRsdTg3ZDBmb2Iya3NjMmRvaHJzc2QifQ.kEde5kOzGqvN_g-wlczYCg";
    let map, currentRoute, departureMarker, arrivalMarker;
    let polylines = {};
    let typingTimers = {};
    let cache = {};

    const taxiLines = <?= json_encode($linesWithPoints, JSON_UNESCAPED_UNICODE) ?>;
    const groupedLines = <?= json_encode($groupedLines, JSON_UNESCAPED_UNICODE) ?>;

    document.addEventListener("DOMContentLoaded", () => {
      initializeMap();
      setupAutocomplete("startPoint", "startSuggestions");
      setupAutocomplete("endPoint", "endSuggestions");
      setupDepartureFilter();
    });

    function initializeMap() {
      map = L.map("map").setView([33.5731, -7.5898], 12);
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
      }).addTo(map);

      // Créer toutes les polylignes
      taxiLines.forEach(line => {
        if (line.points.length > 1) {
          const polyline = L.polyline(line.points, {
            color: line.color,
            weight: 4,
            opacity: 0.7,
          }).addTo(map);
          polylines[line.id] = polyline;
        }
      });
    }

    function toggleGroup(header) {
      const content = header.nextElementSibling;
      const isCollapsed = content.classList.contains('collapsed');
      
      if (isCollapsed) {
        content.classList.remove('collapsed');
        header.classList.remove('collapsed');
        content.style.maxHeight = content.scrollHeight + 'px';
      } else {
        content.classList.add('collapsed');
        header.classList.add('collapsed');
        content.style.maxHeight = '0';
      }
    }

    function setupDepartureFilter() {
      const filter = document.getElementById('departureFilter');
      filter.addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase().trim();
        const groups = document.querySelectorAll('.departure-group');
        
        groups.forEach(group => {
          const departureName = group.getAttribute('data-departure').toLowerCase();
          if (departureName.includes(query)) {
            group.classList.remove('hidden');
          } else {
            group.classList.add('hidden');
          }
        });
        
        // Afficher un message si aucun résultat
        const visibleGroups = document.querySelectorAll('.departure-group:not(.hidden)');
        const container = document.getElementById('groupedRoutes');
        let noResults = container.querySelector('.no-results');
        
        if (visibleGroups.length === 0 && query) {
          if (!noResults) {
            const div = document.createElement('div');
            div.className = 'no-results';
            div.textContent = 'Aucun point de départ trouvé pour "' + query + '"';
            container.appendChild(div);
          }
        } else if (noResults) {
          noResults.remove();
        }
      });
    }

    function zoomToLine(lineId) {
      // Masquer toutes les autres polylignes
      Object.keys(polylines).forEach(id => {
        if (id != lineId) {
          map.removeLayer(polylines[id]);
        } else {
          map.addLayer(polylines[id]);
        }
      });

      // Nettoyer les marqueurs précédents
      if (departureMarker) map.removeLayer(departureMarker);
      if (arrivalMarker) map.removeLayer(arrivalMarker);
      if (currentRoute) map.removeLayer(currentRoute);

      const lineData = taxiLines.find(l => l.id === lineId);
      if (!lineData) return;

      const polyline = polylines[lineId];
      map.fitBounds(polyline.getBounds(), { padding: [50, 50] });

      const start = lineData.points[0];
      const end = lineData.points[lineData.points.length - 1];

      departureMarker = L.marker(start, {
        icon: L.divIcon({
          html: '<div style="background: #22c55e; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">D</div>',
          iconSize: [30, 30],
          className: 'custom-div-icon'
        })
      }).addTo(map).bindPopup("🚀 Départ").openPopup();

      arrivalMarker = L.marker(end, {
        icon: L.divIcon({
          html: '<div style="background: #ef4444; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">A</div>',
          iconSize: [30, 30],
          className: 'custom-div-icon'
        })
      }).addTo(map).bindPopup("🏁 Arrivée");

      const display = document.getElementById("selectedRouteName");
      display.innerHTML = `🚖 <strong>Ligne sélectionnée :</strong> ${lineData.name}`;
      display.style.display = "block";

      // Mettre à jour l'état actif
      document.querySelectorAll('.route-item').forEach(item => item.classList.remove('active'));
      const activeItem = document.querySelector(`[data-line-id="${lineId}"]`);
      if (activeItem) activeItem.classList.add('active');
    }

    function resetView() {
      // Restaurer toutes les polylignes
      Object.values(polylines).forEach(p => map.addLayer(p));
      
      // Nettoyer les marqueurs
      if (departureMarker) map.removeLayer(departureMarker);
      if (arrivalMarker) map.removeLayer(arrivalMarker);
      if (currentRoute) map.removeLayer(currentRoute);
      
      // Réinitialiser l'affichage
      document.getElementById("selectedRouteName").style.display = "none";
      document.querySelectorAll('.route-item').forEach(item => item.classList.remove('active'));
      
      // Réinitialiser la vue de la carte
      map.setView([33.5731, -7.5898], 12);
    }

    function setupAutocomplete(inputId, suggestionsId) {
      const input = document.getElementById(inputId);
      const box = document.getElementById(suggestionsId);

      input.addEventListener("input", () => {
        const query = input.value.trim();
        clearTimeout(typingTimers[inputId]);

        if (query.length < 2) {
          box.style.display = "none";
          return;
        }

        box.innerHTML = "<div class='suggestion-item'>🔍 Recherche...</div>";
        box.style.display = "block";

        typingTimers[inputId] = setTimeout(async () => {
          if (cache[query]) {
            showSuggestions(cache[query], input, box);
            return;
          }

          const proximity = "-7.5898,33.5731";
          const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json?access_token=${MAPBOX_TOKEN}&limit=5&language=fr&proximity=${proximity}`;

          try {
            const res = await fetch(url);
            const data = await res.json();
            cache[query] = data.features;
            showSuggestions(data.features, input, box);
          } catch (error) {
            console.error("Erreur Mapbox:", error);
            box.innerHTML = "<div class='suggestion-item'>❌ Erreur de recherche</div>";
          }
        }, 300);
      });

      input.addEventListener("blur", () => {
        setTimeout(() => box.style.display = "none", 150);
      });
    }

    function showSuggestions(features, input, box) {
      box.innerHTML = "";
      const filtered = features.filter(f => 
        f.context?.some(c => c.text === "Casablanca" || c.text === "Mohammedia")
      );
      
      if (!filtered.length) {
        box.innerHTML = "<div class='suggestion-item'>❌ Aucun résultat</div>";
        return;
      }

      filtered.forEach(place => {
        const div = document.createElement("div");
        div.className = "suggestion-item";
        div.innerHTML = `📍 ${place.place_name}`;
        div.onclick = () => {
          input.value = place.place_name;
          box.style.display = "none";
        };
        box.appendChild(div);
      });
      box.style.display = "block";
    }

    async function searchRouteFromInputs() {
      const start = document.getElementById("startPoint").value;
      const end = document.getElementById("endPoint").value;
      if (start && end) {
        showRouteByName(start, end);
      }
    }

    async function showRouteByName(startName, endName) {
      try {
        const [startRes, endRes] = await Promise.all([
          fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(startName)}.json?access_token=${MAPBOX_TOKEN}&limit=1&language=fr`),
          fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(endName)}.json?access_token=${MAPBOX_TOKEN}&limit=1&language=fr`)
        ]);

        const startData = await startRes.json();
        const endData = await endRes.json();

        if (!startData.features.length || !endData.features.length) {
          alert("❌ Coordonnées introuvables.");
          return;
        }

        const start = startData.features[0].center;
        const end = endData.features[0].center;

        const routeRes = await fetch(`https://router.project-osrm.org/route/v1/driving/${start[0]},${start[1]};${end[0]},${end[1]}?overview=full&geometries=geojson`);
        const routeJson = await routeRes.json();

        if (!routeJson.routes || !routeJson.routes.length) {
          alert("❌ Impossible de calculer l'itinéraire.");
          return;
        }

        const coords = routeJson.routes[0].geometry.coordinates.map(([lon, lat]) => [lat, lon]);
        
        if (currentRoute) map.removeLayer(currentRoute);
        currentRoute = L.polyline(coords, { 
          color: "#e67e22", 
          weight: 5,
          opacity: 0.8,
          dashArray: '10, 10'
        }).addTo(map);
        
        map.fitBounds(currentRoute.getBounds(), { padding: [30, 30] });

        // Afficher les informations de l'itinéraire
        const display = document.getElementById("selectedRouteName");
        display.innerHTML = `🗺️ <strong>Itinéraire calculé :</strong> ${startName} → ${endName}`;
        display.style.display = "block";
        
      } catch (error) {
        console.error("Erreur itinéraire:", error);
        alert("❌ Impossible de tracer le trajet.");
      }
    }

    function useCurrentLocation() {
      if (!navigator.geolocation) {
        alert("❌ Géolocalisation non supportée par votre navigateur");
        return;
      }
      
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          const lat = pos.coords.latitude;
          const lon = pos.coords.longitude;
          
          const marker = L.marker([lat, lon], {
            icon: L.divIcon({
              html: '<div style="background: #3388ff; color: white; border-radius: 50%; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);">📍</div>',
              iconSize: [35, 35],
              className: 'custom-div-icon'
            })
          }).addTo(map).bindPopup("📍 Vous êtes ici").openPopup();
          
          map.setView([lat, lon], 15);
          document.getElementById("startPoint").value = `Position actuelle (${lat.toFixed(4)}, ${lon.toFixed(4)})`;
        },
        (error) => {
          console.error("Erreur géolocalisation:", error);
          alert("❌ Impossible d'obtenir votre position");
        }
      );
    }
  </script>
</body>
</html>