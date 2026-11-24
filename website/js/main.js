/**
 * Main Three.js Scene - Sepid Trade Pars
 * Professional 3D Earth Globe with scroll animations
 */
import * as THREE from 'three';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { initGalaxy } from './galaxy.js';

gsap.registerPlugin(ScrollTrigger);

// ============================================
// LOADING UI & APP START
// ============================================
const loader = document.getElementById('loader');
const loaderBar = document.getElementById('loaderBar');
let appStarted = false;
const minLoaderTime = 900;
const loaderStartedAt = performance.now();

function setLoaderProgress(value) {
    if (!loaderBar) return;
    const safeValue = Math.min(100, Math.max(0, value));
    loaderBar.style.width = `${safeValue}%`;
}

function runIntroAnimations() {
    const introTimeline = gsap.timeline({ defaults: { duration: 1.05, ease: 'power3.out' } });
    introTimeline
        .fromTo('.hero-logo', { y: 40, opacity: 0 }, { y: 0, opacity: 1 }, 0)
        .fromTo('.hero-title', { y: 40, opacity: 0 }, { y: 0, opacity: 1 }, 0.12)
        .fromTo('.hero-subtitle', { y: 40, opacity: 0 }, { y: 0, opacity: 1 }, 0.24)
        .fromTo('.hero-tagline', { y: 40, opacity: 0 }, { y: 0, opacity: 1 }, 0.36)
        .fromTo('.hero-stats', { y: 40, opacity: 0 }, { y: 0, opacity: 1 }, 0.48)
        .fromTo('#hero .cta-button', { y: 40, opacity: 0 }, { y: 0, opacity: 1 }, 0.60);
}

function startApp() {
    if (appStarted) return;
    appStarted = true;
    setLoaderProgress(100);

    setTimeout(() => {
        if (loader) loader.classList.add('hidden');
        document.body.classList.add('intro-done');
        clock.getDelta();
        tick();
        initGalaxy();
        runIntroAnimations();
        ScrollTrigger.refresh();
    }, 250);
}

// ============================================
// STATE CONTAINER
// ============================================
// Initial globe framing for the first screen:
// - Y rotation centers Iran on the first screen.
// - X rotation tilts the globe slightly toward the northern hemisphere.
const INITIAL_GLOBE_ROTATION_Y = Math.PI * 1.215;
const INITIAL_GLOBE_ROTATION_X = 0.38;

const state = {
    scrollProgress: { current: 0, target: 0, ease: 0.08 },
    cameraY: { current: 0, target: 0, ease: 0.06 },
    cameraZ: { current: 8, target: 8, ease: 0.06 },
    mouseX: { current: 0, target: 0, ease: 0.12 },
    mouseY: { current: 0, target: 0, ease: 0.12 },
    globeRotY: { current: INITIAL_GLOBE_ROTATION_Y, target: INITIAL_GLOBE_ROTATION_Y, ease: 0.10 },
    globeRotX: { current: INITIAL_GLOBE_ROTATION_X, target: INITIAL_GLOBE_ROTATION_X, ease: 0.10 },
};

// ============================================
// LERP HELPER
// ============================================
function damp(s, delta) {
    const factor = 1 - Math.pow(1 - s.ease, delta * 60);
    s.current += (s.target - s.current) * factor;
}

// ============================================
// SCENE SETUP
// ============================================
const canvas = document.getElementById('webgl');
const scene = new THREE.Scene();
scene.fog = new THREE.FogExp2(0x0a0f1c, 0.08);

const camera = new THREE.PerspectiveCamera(35, window.innerWidth / window.innerHeight, 0.1, 100);
camera.position.set(0, 0, 8);

const renderer = new THREE.WebGLRenderer({
    canvas,
    antialias: true,
    alpha: true,
});
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.35));
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.45;
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.setClearColor(0x000000, 0);

const clock = new THREE.Clock();

// ============================================
// SCENE GROUPS
// ============================================
const lighting = new THREE.Group();
lighting.name = 'lighting';
scene.add(lighting);

const stage = new THREE.Group();
stage.name = 'stage';
scene.add(stage);

// ============================================
// LIGHTING
// ============================================
const hemi = new THREE.HemisphereLight(0xf4fbff, 0x1b2740, 1.05);
lighting.add(hemi);

const ambient = new THREE.AmbientLight(0x9fc8ff, 0.55);
lighting.add(ambient);

const key = new THREE.DirectionalLight(0xfff2df, 1.85);
key.position.set(5, 8, 5);
lighting.add(key);

const fill = new THREE.DirectionalLight(0x88aaff, 0.4);
fill.position.set(-5, 3, 4);
lighting.add(fill);

const rim = new THREE.DirectionalLight(0xffffff, 0.8);
rim.position.set(0, 6, -8);
lighting.add(rim);

// ============================================
// 3D EARTH GLOBE
// ============================================
const loadingManager = new THREE.LoadingManager();
loadingManager.onProgress = (_url, itemsLoaded, itemsTotal) => {
    setLoaderProgress((itemsLoaded / itemsTotal) * 100);
};
loadingManager.onLoad = () => {
    const elapsed = performance.now() - loaderStartedAt;
    window.setTimeout(startApp, Math.max(0, minLoaderTime - elapsed));
};
loadingManager.onError = (url) => {
    console.warn(`Asset failed to load: ${url}`);
};

const textureLoader = new THREE.TextureLoader(loadingManager);
const gltfLoader = new GLTFLoader(loadingManager);
setLoaderProgress(8);

const earthDayTexture = textureLoader.load('textures/free3d_earth_albedo.jpg');
earthDayTexture.colorSpace = THREE.SRGBColorSpace;


const earthBumpTexture = textureLoader.load('textures/free3d_earth_bump.jpg');

const earthCloudTexture = textureLoader.load('textures/free3d_earth_clouds.webp');
earthCloudTexture.colorSpace = THREE.SRGBColorSpace;

const earthLandOceanMaskTexture = textureLoader.load('textures/free3d_earth_land_ocean_mask.png');

const moonTexture = textureLoader.load('textures/moon_texture_2k.jpg');
moonTexture.colorSpace = THREE.SRGBColorSpace;

const earthGeometry = new THREE.SphereGeometry(2, 48, 48);
const earthMaterial = new THREE.MeshStandardMaterial({
    map: earthDayTexture,
    bumpMap: earthBumpTexture,
    bumpScale: 0.045,
    roughnessMap: earthLandOceanMaskTexture,
    metalness: 0.12,
    roughness: 0.66,
    emissive: 0xffffff,
    emissiveMap: earthDayTexture,
    emissiveIntensity: 0.05,
});

const globe = new THREE.Mesh(earthGeometry, earthMaterial);
globe.position.set(0, 0, 0);
stage.add(globe);


const earthCloudMaterial = new THREE.MeshBasicMaterial({
    map: earthCloudTexture,
    alphaMap: earthCloudTexture,
    color: 0xffffff,
    transparent: true,
    opacity: 0.34,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
});

const globeClouds = new THREE.Mesh(
    new THREE.SphereGeometry(2.035, 64, 64),
    earthCloudMaterial
);
globe.add(globeClouds);


// ============================================
// 3D MOON ORBIT
// ============================================
const moonOrbit = new THREE.Group();
moonOrbit.name = 'moon-orbit';
stage.add(moonOrbit);

const moonMaterial = new THREE.MeshStandardMaterial({
    map: moonTexture,
    roughness: 0.95,
    metalness: 0.0,
    emissive: 0x555555,
    emissiveIntensity: 0.08,
});
const moon = new THREE.Mesh(new THREE.SphereGeometry(0.22, 48, 48), moonMaterial);
moon.position.set(3.55, 0.48, -0.55);
moonOrbit.rotation.x = THREE.MathUtils.degToRad(8);
moonOrbit.rotation.z = THREE.MathUtils.degToRad(-18);
moonOrbit.add(moon);


// The decorative atmosphere halo, wireframe and orbit rings were intentionally removed.
// The scene now keeps only the Earth globe itself.

// ============================================
// SUBTLE 3D FLIGHT ROUTES AROUND IRAN
// ============================================
const flightGroup = new THREE.Group();
flightGroup.name = 'iran-flight-routes';
globe.add(flightGroup);

const flightRoutes = [];
const routeClock = new THREE.Clock();

function latLonToVector3(lat, lon, radius = 2) {
    const latRad = THREE.MathUtils.degToRad(lat);
    const lonRad = THREE.MathUtils.degToRad(-lon);
    return new THREE.Vector3(
        Math.cos(latRad) * Math.cos(lonRad) * radius,
        Math.sin(latRad) * radius,
        Math.cos(latRad) * Math.sin(lonRad) * radius
    );
}

function createArcCurve(start, end, radius = 2.05, altitude = 0.34) {
    const startDir = latLonToVector3(start.lat, start.lon, 1).normalize();
    const endDir = latLonToVector3(end.lat, end.lon, 1).normalize();
    const points = [];

    for (let i = 0; i <= 72; i += 1) {
        const t = i / 72;
        const dir = startDir.clone().lerp(endDir, t).normalize();
        const arcHeight = radius + Math.sin(Math.PI * t) * altitude;
        points.push(dir.multiplyScalar(arcHeight));
    }

    return new THREE.CatmullRomCurve3(points);
}

let airplaneTemplate = null;
const airplaneInstances = [];
const AIRPLANE_DISPLAY_LENGTH = 0.135;

function prepareAirplaneTemplate(gltfScene) {
    const source = gltfScene.clone(true);

    source.traverse((child) => {
        if (!child.isMesh) return;
        child.frustumCulled = false;
        if (child.material) {
            child.material = child.material.clone();
            child.material.metalness = child.material.metalness ?? 0.25;
            child.material.roughness = Math.min(child.material.roughness ?? 0.45, 0.55);
        }
    });

    const box = new THREE.Box3().setFromObject(source);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const longestSide = Math.max(size.x, size.y, size.z) || 1;

    source.position.sub(center);

    const wrapper = new THREE.Group();
    wrapper.name = 'realistic-airplane-model';
    wrapper.scale.setScalar(AIRPLANE_DISPLAY_LENGTH / longestSide);
    wrapper.add(source);

    return wrapper;
}


function populateAirplaneContainer(container) {
    container.clear();
    if (!airplaneTemplate) return;

    const model = airplaneTemplate.clone(true);
    const routeColor = new THREE.Color(container.userData.routeColor || 0xffffff);
    model.traverse((child) => {
        if (child.isMesh && child.material) {
            child.material = child.material.clone();
            if (child.material.color) {
                // Very soft tint only, so the aircraft remains natural/realistic.
                child.material.color.lerp(routeColor, 0.10);
            }
        }
    });

    container.add(model);
}

function createAirplaneModel(color = 0xffffff) {
    const container = new THREE.Group();
    container.name = 'realistic-route-airplane';
    container.userData.routeColor = color;
    airplaneInstances.push(container);
    populateAirplaneContainer(container);
    return container;
}

gltfLoader.load(
    'models/a380-model.glb',
    (gltf) => {
        airplaneTemplate = prepareAirplaneTemplate(gltf.scene);
        airplaneInstances.forEach(populateAirplaneContainer);
    },
    undefined,
    (error) => {
        console.warn('Realistic airplane model failed to load:', error);
    }
);

function orientObjectOnRoute(object, position, tangent) {
    const forward = tangent.clone().normalize();
    const up = position.clone().normalize();
    const right = new THREE.Vector3().crossVectors(up, forward).normalize();
    const correctedUp = new THREE.Vector3().crossVectors(forward, right).normalize();
    const matrix = new THREE.Matrix4().makeBasis(right, correctedUp, forward);
    object.quaternion.setFromRotationMatrix(matrix);
}

function addFlightRoute({ start, end, color = 0xf4a261, duration = 52000, delay = 0, opacity = 0.32 }) {
    const curve = createArcCurve(start, end);
    const routeMaterial = new THREE.MeshBasicMaterial({
        color,
        transparent: true,
        opacity,
        depthWrite: false,
    });
    const routeLine = new THREE.Mesh(new THREE.TubeGeometry(curve, 72, 0.0038, 5, false), routeMaterial);
    flightGroup.add(routeLine);

    const plane = createAirplaneModel(color);
    flightGroup.add(plane);

    flightRoutes.push({ curve, plane, duration, delay });
}

function addHubMarker(lat, lon) {
    const normal = latLonToVector3(lat, lon, 1).normalize();
    const marker = new THREE.Group();
    marker.name = 'iran-flight-hub-marker';
    marker.position.copy(normal.clone().multiplyScalar(2.058));
    marker.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), normal);

    const core = new THREE.Mesh(
        new THREE.SphereGeometry(0.012, 18, 18),
        new THREE.MeshBasicMaterial({ color: 0xfff1a8 })
    );
    const ring = new THREE.Mesh(
        new THREE.TorusGeometry(0.030, 0.0032, 10, 40),
        new THREE.MeshBasicMaterial({
            color: 0xd4af37,
            transparent: true,
            opacity: 0.78,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
        })
    );
    const outerGlow = new THREE.Mesh(
        new THREE.TorusGeometry(0.046, 0.002, 10, 48),
        new THREE.MeshBasicMaterial({
            color: 0xd4af37,
            transparent: true,
            opacity: 0.32,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
        })
    );

    marker.add(core, ring, outerGlow);
    flightGroup.add(marker);
}

const tehran = { lat: 35.6892, lon: 51.3890 };
// Accurate inland/city coordinates; East Asia points are slightly west-adjusted to align visually with the Earth texture.
const china = { lat: 34.3416, lon: 108.9398 };       // China / Xi'an, shifted westward for better visual alignment
const korea = { lat: 37.5665, lon: 126.2000 };       // Korea / Seoul area, slight west visual correction
const japan = { lat: 34.6937, lon: 135.5023 };       // Japan / Osaka, shifted left from Tokyo
const india = { lat: 28.6139, lon: 77.2090 };        // India / New Delhi
const russiaWest = { lat: 55.7558, lon: 37.6173 };   // Russia / Moscow
const russiaEast = { lat: 55.0084, lon: 82.9357 };   // Russia / Novosibirsk
const saudi = { lat: 24.7136, lon: 46.6753 };        // Saudi Arabia / Riyadh
const egypt = { lat: 30.0444, lon: 31.2357 };        // Egypt / Cairo
const africa = { lat: -1.2921, lon: 36.8219 };       // Africa / Nairobi
const australia = { lat: -23.6980, lon: 133.8807 };  // Australia / Alice Springs, central inland point
const germany = { lat: 50.1109, lon: 8.6821 };       // Germany / Frankfurt

try {
    addHubMarker(tehran.lat, tehran.lon);

    // Iran-focused air network. Speeds are slightly faster than before.
    addFlightRoute({ start: china,       end: tehran,     color: 0xd4af37, duration: 62000, delay: 0,     opacity: 0.34 });
    addFlightRoute({ start: tehran,      end: korea,      color: 0x8ecae6, duration: 65000, delay: 8000,  opacity: 0.30 });
    addFlightRoute({ start: japan,       end: tehran,     color: 0xffffff, duration: 76000, delay: 16000, opacity: 0.28 });
    addFlightRoute({ start: tehran,      end: india,      color: 0xffb703, duration: 56000, delay: 24000, opacity: 0.30 });
    addFlightRoute({ start: russiaWest,  end: tehran,     color: 0xe6c75a, duration: 60000, delay: 32000, opacity: 0.25 });
    addFlightRoute({ start: tehran,      end: russiaEast, color: 0xbde0fe, duration: 78000, delay: 40000, opacity: 0.23 });
    addFlightRoute({ start: saudi,       end: tehran,     color: 0xfefae0, duration: 52000, delay: 48000, opacity: 0.24 });
    addFlightRoute({ start: tehran,      end: egypt,      color: 0xffddd2, duration: 59000, delay: 56000, opacity: 0.24 });
    addFlightRoute({ start: africa,      end: tehran,     color: 0xd8f3dc, duration: 72000, delay: 64000, opacity: 0.22 });
    addFlightRoute({ start: tehran,      end: australia,  color: 0xa2d2ff, duration: 98000, delay: 72000, opacity: 0.20 });
    addFlightRoute({ start: germany,     end: tehran,     color: 0xe9ecef, duration: 69000, delay: 80000, opacity: 0.24 });
} catch (error) {
    console.warn('Flight routes disabled:', error);
}

function updateFlightRoutes() {
    const elapsed = routeClock.getElapsedTime() * 1000;
    flightRoutes.forEach(({ curve, plane, duration, delay }) => {
        const cycle = duration * 2;
        const raw = ((elapsed - delay) % cycle + cycle) % cycle;
        const outbound = raw <= duration;
        const t = outbound ? raw / duration : 1 - ((raw - duration) / duration);
        const position = curve.getPointAt(t);
        const tangent = curve.getTangentAt(t).multiplyScalar(outbound ? 1 : -1);
        plane.position.copy(position);
        orientObjectOnRoute(plane, position, tangent);
    });
}


// ============================================
// COMMERCIAL SHIPS ON SEA ROUTES
// ============================================
const shipGroup = new THREE.Group();
shipGroup.name = 'commercial-sea-traffic';
globe.add(shipGroup);

const shipRoutes = [];
const shipClock = new THREE.Clock();

function createSurfaceCurve(start, end, radius = 2.028, lift = 0.018) {
    const startDir = latLonToVector3(start.lat, start.lon, 1).normalize();
    const endDir = latLonToVector3(end.lat, end.lon, 1).normalize();
    const points = [];

    for (let i = 0; i <= 80; i += 1) {
        const t = i / 80;
        const dir = startDir.clone().lerp(endDir, t).normalize();
        points.push(dir.multiplyScalar(radius + Math.sin(Math.PI * t) * lift));
    }

    return new THREE.CatmullRomCurve3(points, false, 'catmullrom', 0.45);
}

function createShipModel(type = 'container') {
    const ship = new THREE.Group();
    ship.name = `${type}-ship`;

    const hullMat = new THREE.MeshStandardMaterial({ color: type === 'tanker' ? 0x8b1e2d : 0x17324d, metalness: 0.45, roughness: 0.34 });
    const deckMat = new THREE.MeshStandardMaterial({ color: 0xd9e2ec, metalness: 0.25, roughness: 0.42 });
    const bridgeMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.18, roughness: 0.35 });
    const glassMat = new THREE.MeshBasicMaterial({ color: 0x0b1f35 });

    const hull = new THREE.Mesh(new THREE.BoxGeometry(0.038, 0.016, 0.142), hullMat);
    hull.position.y = -0.006;
    ship.add(hull);

    const bow = new THREE.Mesh(new THREE.ConeGeometry(0.019, 0.035, 4), hullMat);
    bow.rotation.x = Math.PI / 2;
    bow.rotation.z = Math.PI / 4;
    bow.position.set(0, -0.006, 0.088);
    ship.add(bow);

    const deck = new THREE.Mesh(new THREE.BoxGeometry(0.034, 0.006, 0.112), deckMat);
    deck.position.y = 0.005;
    deck.position.z = -0.008;
    ship.add(deck);

    if (type === 'container') {
        const colors = [0xd62828, 0xf77f00, 0x1d4ed8, 0xd4af37, 0xfacc15, 0x7c3aed];
        for (let row = 0; row < 3; row += 1) {
            for (let col = 0; col < 4; col += 1) {
                const mat = new THREE.MeshStandardMaterial({ color: colors[(row + col) % colors.length], roughness: 0.5 });
                const box = new THREE.Mesh(new THREE.BoxGeometry(0.010, 0.007, 0.018), mat);
                box.position.set((row - 1) * 0.011, 0.013 + (col % 2) * 0.006, -0.038 + col * 0.018);
                ship.add(box);
            }
        }
    } else if (type === 'tanker') {
        for (let i = 0; i < 3; i += 1) {
            const tank = new THREE.Mesh(new THREE.CylinderGeometry(0.010, 0.010, 0.052, 16), new THREE.MeshStandardMaterial({ color: 0xcbd5e1, metalness: 0.38, roughness: 0.28 }));
            tank.rotation.z = Math.PI / 2;
            tank.position.set(0, 0.014, -0.034 + i * 0.034);
            ship.add(tank);
        }
    } else {
        for (let i = 0; i < 4; i += 1) {
            const hatch = new THREE.Mesh(new THREE.BoxGeometry(0.025, 0.004, 0.017), new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.55 }));
            hatch.position.set(0, 0.012, -0.048 + i * 0.026);
            ship.add(hatch);
        }
    }

    const bridge = new THREE.Mesh(new THREE.BoxGeometry(0.022, 0.020, 0.018), bridgeMat);
    bridge.position.set(0, 0.022, -0.066);
    ship.add(bridge);

    const windowBand = new THREE.Mesh(new THREE.BoxGeometry(0.023, 0.004, 0.003), glassMat);
    windowBand.position.set(0, 0.027, -0.056);
    ship.add(windowBand);

    ship.scale.setScalar(type === 'tanker' ? 1.06 : 0.92);
    return ship;
}

function orientStaticShip(ship, lat, lon, headingDegrees = 90) {
    const position = latLonToVector3(lat, lon, 2.032);
    const headingRad = THREE.MathUtils.degToRad(headingDegrees);
    const lat2 = lat + Math.cos(headingRad) * 0.4;
    const lon2 = lon + (Math.sin(headingRad) * 0.4) / Math.max(0.2, Math.cos(THREE.MathUtils.degToRad(lat)));
    const next = latLonToVector3(lat2, lon2, 2.032);
    const tangent = next.sub(position).normalize();
    ship.position.copy(position);
    orientObjectOnRoute(ship, position, tangent);
}

function addStaticShip({ type, lat, lon, heading }) {
    const ship = createShipModel(type);
    shipGroup.add(ship);
    orientStaticShip(ship, lat, lon, heading);
    return ship;
}

function addMovingShip({ type = 'container', start, end, duration = 42000, delay = 0 }) {
    const curve = createSurfaceCurve(start, end);
    const ship = createShipModel(type);
    shipGroup.add(ship);
    shipRoutes.push({ curve, ship, duration, delay });
}

try {
    // Only one ship inside the Persian Gulf / Strait of Hormuz; the rest are in open water.
    addMovingShip({ type: 'container', start: { lat: 26.62, lon: 56.30 }, end: { lat: 25.35, lon: 58.15 }, duration: 36000, delay: 0 });
    addMovingShip({ type: 'tanker',    start: { lat: 14.8,  lon: 62.5  }, end: { lat: 12.2,  lon: 70.2  }, duration: 42000, delay: 7000  });
    addMovingShip({ type: 'bulk',      start: { lat: 5.0,   lon: 78.0  }, end: { lat: 3.4,   lon: 88.5  }, duration: 45000, delay: 14000 });
    addMovingShip({ type: 'container', start: { lat: 1.1,   lon: 103.4 }, end: { lat: 5.0,   lon: 110.4 }, duration: 41000, delay: 21000 });
    addMovingShip({ type: 'tanker',    start: { lat: 26.8,  lon: 123.4 }, end: { lat: 33.6,  lon: 128.6 }, duration: 47000, delay: 28000 });
    addMovingShip({ type: 'bulk',      start: { lat: -24.8, lon: 108.5 }, end: { lat: -18.5, lon: 115.5 }, duration: 48000, delay: 35000 });
    addMovingShip({ type: 'tanker',    start: { lat: 18.0,  lon: 38.4  }, end: { lat: 25.2,  lon: 36.2  }, duration: 43000, delay: 42000 });
    addMovingShip({ type: 'container', start: { lat: 34.2,  lon: 18.0  }, end: { lat: 35.3,  lon: 28.2  }, duration: 44000, delay: 49000 });
} catch (error) {
    console.warn('Ships disabled:', error);
}

function updateShipRoutes() {
    const elapsed = shipClock.getElapsedTime() * 1000;
    shipRoutes.forEach(({ curve, ship, duration, delay }) => {
        const resetPause = 1200;
        const raw = ((elapsed - delay) % (duration + resetPause) + (duration + resetPause)) % (duration + resetPause);

        if (raw > duration) {
            ship.visible = false;
            return;
        }

        ship.visible = true;
        const t = raw / duration;
        const position = curve.getPointAt(t);
        const tangent = curve.getTangentAt(t);
        const bob = 1 + Math.sin(elapsed * 0.004 + delay * 0.001) * 0.0018;
        ship.position.copy(position.clone().multiplyScalar(bob));
        orientObjectOnRoute(ship, ship.position, tangent);
        ship.rotateZ(Math.sin(elapsed * 0.003 + delay * 0.001) * 0.022);
    });
}

// ============================================
// RENDERING NOTE
// ============================================
// Post-processing bloom was removed to keep the site lightweight and prevent
// high GPU/RAM usage on weaker systems. The renderer now draws the transparent
// Earth scene directly over the star canvas.

// ============================================
// LENIS SMOOTH SCROLL
// ============================================

// Detect touch devices where Lenis sometimes causes scroll locks
const isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;
const isAndroid = /Android/i.test(navigator.userAgent);
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// On Android Chrome, Lenis + ScrollTrigger can cause scroll freezes.
// We use native scrolling on these devices for reliability.
const useNativeScroll = isCoarsePointer || isAndroid;

let lenis = null;
if (!useNativeScroll) {
    lenis = new Lenis({
        duration: 1.2,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
        smoothWheel: true,
        prevent: () => false,
    });

    lenis.on('scroll', ScrollTrigger.update);

    gsap.ticker.add((time) => {
        lenis.raf(time * 1000);
    });
    gsap.ticker.lagSmoothing(0);
} else {
    // Native scroll path: keep ScrollTrigger in sync with the real scroll position
    ScrollTrigger.defaults({ scroller: window });
    window.addEventListener('scroll', () => ScrollTrigger.update(), { passive: true });
}

// Helpful for debugging
if (prefersReducedMotion) {
    document.documentElement.style.setProperty('scroll-behavior', 'auto');
}

// ============================================
// SCROLL-DRIVEN ANIMATIONS
// ============================================
const sections = document.querySelectorAll('.vh-100');

const scrollSceneTl = gsap.timeline({
    scrollTrigger: {
        trigger: '.scroll-container',
        start: 'top top',
        end: 'bottom bottom',
        scrub: 0.7,
    },
});

// Single monotonic timeline: no overlapping section tweens, no reverse hiccups.
scrollSceneTl
    // Hero: hold Earth still while the logo emerges.
    .to(state.cameraY, { target: 0, ease: 'none', duration: 0.75 }, 0)
    .to(state.cameraZ, { target: 8, ease: 'none', duration: 0.75 }, 0)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y, ease: 'none', duration: 0.75 }, 0)

    // About / services: begin smooth rightward rotation.
    .to(state.cameraY, { target: -1.45, ease: 'none', duration: 0.75 }, 0.75)
    .to(state.cameraZ, { target: 6.7, ease: 'none', duration: 0.75 }, 0.75)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 0.85, ease: 'none', duration: 0.75 }, 0.75)

    .to(state.cameraY, { target: -0.65, ease: 'none', duration: 0.75 }, 1.50)
    .to(state.cameraZ, { target: 7.1, ease: 'none', duration: 0.75 }, 1.50)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 1.45, ease: 'none', duration: 0.75 }, 1.50)

    // Network: Earth returns to the center from above.
    .to(state.cameraY, { target: 0, ease: 'none', duration: 0.85 }, 2.25)
    .to(state.cameraZ, { target: 7.1, ease: 'none', duration: 0.85 }, 2.25)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 2.05, ease: 'none', duration: 0.85 }, 2.25)

    // Why us: Earth moves down.
    .to(state.cameraY, { target: 1.55, ease: 'none', duration: 0.85 }, 3.10)
    .to(state.cameraZ, { target: 8.6, ease: 'none', duration: 0.85 }, 3.10)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 2.72, ease: 'none', duration: 0.85 }, 3.10)

    // Process + Team.
    .to(state.cameraY, { target: 0.65, ease: 'none', duration: 0.8 }, 3.95)
    .to(state.cameraZ, { target: 7.7, ease: 'none', duration: 0.8 }, 3.95)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 3.35, ease: 'none', duration: 0.8 }, 3.95)

    .to(state.cameraY, { target: -0.25, ease: 'none', duration: 0.75 }, 4.75)
    .to(state.cameraZ, { target: 7.3, ease: 'none', duration: 0.75 }, 4.75)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 3.70, ease: 'none', duration: 0.75 }, 4.75)

    // Contact: center and zoom back to Iran; rotation stays monotonic by using +4π.
    .to(state.cameraY, { target: 0, ease: 'none', duration: 0.9 }, 5.50)
    .to(state.cameraZ, { target: 4.85, ease: 'none', duration: 0.9 }, 5.50)
    .to(state.globeRotY, { target: INITIAL_GLOBE_ROTATION_Y + Math.PI * 4.0, ease: 'none', duration: 0.9 }, 5.50);

// ============================================
// SECTION REVEALS
// ============================================
sections.forEach((section, index) => {
    if (index === 0) return;
    const content = section.querySelector('.section-content');
    if (!content) return;

    gsap.fromTo(content.children,
        { y: 60, opacity: 0 },
        {
            y: 0,
            opacity: 1,
            duration: 1,
            stagger: 0.15,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: section,
                start: 'top 70%',
                toggleActions: 'play none none reverse',
            },
        }
    );
});



// ============================================
// SCROLL-DRIVEN LOGO MORPH
// ============================================
const scrollLogoAnim = document.getElementById('scrollLogoAnim');

function smoothStep(t) {
    return t * t * (3 - 2 * t);
}

function clamp01(value) {
    return Math.min(1, Math.max(0, value));
}

function lerp(a, b, t) {
    return a + (b - a) * t;
}

function updateScrollLogoAnimation() {
    if (!scrollLogoAnim) return;

    const progress = clamp01(window.scrollY / (window.innerHeight * 1.18));
    const emerge = smoothStep(clamp01(progress / 0.38));
    const dock = smoothStep(clamp01((progress - 0.42) / 0.36));

    const globeSize = Math.min(window.innerWidth * 0.42, window.innerHeight * 0.50, 430);
    const centerX = window.innerWidth * 0.5 + state.mouseX.current * 42;
    const centerY = window.innerHeight * 0.50 - state.mouseY.current * 24;
    const targetX = window.innerWidth - 68;
    const targetY = 43;

    const x = lerp(centerX, targetX, dock);
    const y = lerp(centerY, targetY, dock);
    const size = lerp(24, globeSize, emerge) * (1 - dock) + 44 * dock;
    const appear = clamp01((progress - 0.015) / 0.08);
    const fadeToNav = dock > 0.82 ? clamp01(1 - (dock - 0.82) / 0.18) : 1;
    const opacity = appear * fadeToNav;

    const heroContent = document.querySelector('#hero .hero-content');
    if (heroContent) {
        const textFade = 1 - smoothStep(clamp01((progress - 0.07) / 0.18));
        heroContent.style.opacity = textFade.toFixed(3);
        heroContent.style.pointerEvents = textFade < 0.2 ? 'none' : 'auto';
    }

    scrollLogoAnim.style.left = `${x}px`;
    scrollLogoAnim.style.top = `${y}px`;
    scrollLogoAnim.style.width = `${size}px`;
    scrollLogoAnim.style.opacity = opacity.toFixed(3);

    document.body.classList.toggle('logo-docked', progress > 0.75);
}

// ============================================
// MOUSE PARALLAX
// ============================================
window.addEventListener('mousemove', (e) => {
    state.mouseX.target = (e.clientX / window.innerWidth) * 2 - 1;
    state.mouseY.target = -((e.clientY / window.innerHeight) * 2 - 1);
}, { passive: true });



// ============================================
// TEAM CHROMA CARD INTERACTION
// ============================================
function initChromaTeamCards() {
    document.querySelectorAll('.chroma-card').forEach((card) => {
        card.addEventListener('mousemove', (event) => {
            const rect = card.getBoundingClientRect();
            card.style.setProperty('--mouse-x', `${event.clientX - rect.left}px`);
            card.style.setProperty('--mouse-y', `${event.clientY - rect.top}px`);
        });
    });
}

initChromaTeamCards();

// ============================================
// NAVBAR
// ============================================

const navToggle = document.getElementById('navToggle');
const navLinksContainer = document.querySelector('.nav-links');

navToggle?.addEventListener('click', () => {
    const isOpen = navLinksContainer?.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});

navLinksContainer?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
        navLinksContainer.classList.remove('open');
        navToggle?.setAttribute('aria-expanded', 'false');
    });
});


window.addEventListener('scroll', () => {
    const navbar = document.getElementById('navbar');
    if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }

    const navLinks = document.querySelectorAll('.nav-link');
    let current = '';
    sections.forEach((section) => {
        const sectionTop = section.offsetTop;
        if (window.pageYOffset >= sectionTop - 200) {
            current = section.getAttribute('id');
        }
    });
    navLinks.forEach((link) => {
        if (link.getAttribute('href') === '#' + current) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
});

document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (!href || href === '#') return;

        let target = null;
        try {
            target = document.querySelector(href);
        } catch (_error) {
            return;
        }

        if (target) {
            e.preventDefault();
            if (lenis) {
                lenis.scrollTo(target, { offset: 0 });
            } else {
                // Native scroll fallback for touch devices
                target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
            }
        }
    });
});

// ============================================
// RESIZE
// ============================================
let resizePending = false;
function onResize() {
    if (resizePending) return;
    resizePending = true;
    requestAnimationFrame(() => {
        const w = window.innerWidth;
        const h = window.innerHeight;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.35));
        resizePending = false;
    });
}
window.addEventListener('resize', onResize);

// ============================================
// VISIBILITY API
// ============================================
let running = true;

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        running = false;
    } else {
        running = true;
        clock.getDelta();
    }
});

// ============================================
// ANIMATION LOOP
// ============================================
const targetFrameMs = 1000 / 42;
let lastRenderMs = 0;

function tick() {
    if (!running) {
        requestAnimationFrame(tick);
        return;
    }

    const nowMs = performance.now();
    if (nowMs - lastRenderMs < targetFrameMs) {
        requestAnimationFrame(tick);
        return;
    }
    lastRenderMs = nowMs;

    const delta = Math.min(clock.getDelta(), 0.1);

    for (const key in state) {
        damp(state[key], delta);
    }

    camera.position.y = state.cameraY.current;
    camera.position.z = state.cameraZ.current;
    camera.position.x = state.mouseX.current * 0.3;

    globe.rotation.y = state.globeRotY.current;
    globe.rotation.x = state.globeRotX.current;

    globeClouds.rotation.y += delta * 0.010;

    moonOrbit.rotation.y += delta * 0.055;
    moon.rotation.y += delta * 0.12;

    updateFlightRoutes();
    updateShipRoutes();
    updateScrollLogoAnimation();

    renderer.render(scene, camera);
    requestAnimationFrame(tick);
}

// ============================================
// LOADING FALLBACK
// ============================================
window.setTimeout(() => {
    if (!appStarted) startApp();
}, 6000);

if ('requestIdleCallback' in window) {
    requestIdleCallback(() => {
        renderer.compile(scene, camera);
        renderer.render(scene, camera);
    }, { timeout: 1000 });
}
