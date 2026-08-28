import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

// Pool de tokens distincts produit par `php artisan files:seed-perf` — voir
// backend/perf/README dans le mode d'emploi de la campagne. SharedArray :
// chargé une fois en mémoire, partagé entre tous les VU sans être recopié.
const tokens = new SharedArray('tokens', function () {
  return JSON.parse(open('../tokens.json'));
});

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const SCENARIO = __ENV.K6_SCENARIO || 'calibrage';

// Les trois scénarios actés (A4/A14) : un seul fichier, un seul choisi à la
// fois via K6_SCENARIO, pour ne pas dupliquer la logique de requête.
const SCENARIOS = {
  // 1. Calibrage : 1 VU, 30 s, plafonds relevés — latence/débit de référence
  // sans aucune contention, ni limiteur ni file d'attente.
  calibrage: {
    executor: 'constant-vus',
    vus: 1,
    duration: '30s',
  },

  // 2. Montée : paliers 5 → 20 → 50 VU, plafonds relevés, sur le pool de N
  // liens distincts (pas de mesure du limiteur par token, pas de cache pages
  // noyau sur un fichier unique relu en boucle).
  montee: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '30s', target: 5 },
      { duration: '1m', target: 5 },
      { duration: '30s', target: 20 },
      { duration: '1m', target: 20 },
      { duration: '30s', target: 50 },
      { duration: '1m', target: 50 },
      { duration: '30s', target: 0 },
    ],
  },

  // 3. Dépassement délibéré : plafonds nominaux (aucune variable
  // DATASHARE_THROTTLE_* positionnée au lancement du serveur) — on vérifie
  // que le 429 et Retry-After tiennent sous charge.
  depassement: {
    executor: 'constant-vus',
    vus: 10,
    duration: '30s',
  },
};

export const options = {
  scenarios: {
    [SCENARIO]: SCENARIOS[SCENARIO],
  },
};

export default function () {
  // Round-robin par VU/itération sur le pool : suffisant pour répartir la
  // charge sur les N liens sans qu'aucun VU ne retombe systématiquement sur
  // le même token qu'un voisin.
  const token = tokens[(__VU + __ITER) % tokens.length];

  const res = http.post(`${BASE_URL}/api/links/${token}/download`);

  if (SCENARIO === 'depassement') {
    // Aux plafonds nominaux (60/min api, 30/min downloads_per_ip, 10/min
    // downloads_per_token), le premier à répondre 429 sous cette charge est
    // le limiteur `api` général (par IP) : il est évalué par
    // `bootstrap/app.php` avant même que la route `downloads` ne soit
    // atteinte. Le 429 constaté ici ne prouve donc pas spécifiquement le
    // limiteur `downloads` — seulement le comportement HTTP attendu (429 +
    // Retry-After) sous dépassement, quel que soit le limiteur émetteur.
    check(res, {
      'status is 200 or 429': (r) => r.status === 200 || r.status === 429,
      '429 responses carry Retry-After': (r) => r.status !== 429 || r.headers['Retry-After'] !== undefined,
    });
  } else {
    check(res, {
      'status is 200': (r) => r.status === 200,
    });
  }
}
