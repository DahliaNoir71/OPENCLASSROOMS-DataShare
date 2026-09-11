// Scénarios d'erreur du lien de partage, distincts du parcours nominal
// (parcours-partage.cy.ts) : un mauvais mot de passe (401) et un lien expiré
// (410). L'expiration ne peut pas être attendue en temps réel dans un test
// e2e : elle est donc forcée côté serveur par `php artisan e2e:expire-link`
// (cy.exec), une commande artisan gardée par l'environnement et réservée aux
// tests et démonstrations. Cypress vide localStorage entre les tests : ce
// test démarre donc déconnecté, comme parcours-partage.cy.ts.

const FIXTURE = 'rapport-e2e.txt'
const ACCOUNT_PASSWORD = 'Passer-e2e-1'
const SHARE_PASSWORD = 'Partage-e2e-1'
const WRONG_SHARE_PASSWORD = 'Mauvais-mdp-1'

function uniqueEmail(): string {
  return `e2e+${Date.now()}@example.test`
}

describe('Scénarios d\'erreur du lien de partage', () => {
  let token: string

  beforeEach(() => {
    const email = uniqueEmail()

    cy.request('POST', '/api/auth/register', {
      email,
      password: ACCOUNT_PASSWORD,
      password_confirmation: ACCOUNT_PASSWORD,
    })

    cy.login(email, ACCOUNT_PASSWORD)

    // Dépôt via l'interface, comme le parcours nominal : le token du lien
    // n'est disponible qu'une fois le fichier en ligne, il n'existe pas de
    // raccourci API pour l'obtenir.
    cy.visit('/')
    cy.get('button[aria-label="Téléverser un fichier"]').click()

    cy.get('#upload-file').selectFile(`cypress/fixtures/${FIXTURE}`)
    cy.get('#upload-password').type(SHARE_PASSWORD)
    cy.contains('button', 'Téléverser').click()

    cy.contains('Félicitations, votre fichier est en ligne !')

    cy.get('.upload-link')
      .invoke('attr', 'href')
      .then((href) => {
        token = (href ?? '').split('/').filter(Boolean).pop() ?? ''
      })
  })

  it('refuse un mauvais mot de passe de partage (401)', () => {
    cy.visit(`/l/${token}`)

    cy.get('#download-password').type(WRONG_SHARE_PASSWORD)

    cy.intercept('POST', '**/api/links/*/download').as('download')
    cy.contains('button', 'Télécharger').click()

    cy.wait('@download').then(({ response }) => {
      expect(response?.statusCode).to.eq(401)
    })

    cy.get('#download-password-error').should('have.text', 'Mot de passe incorrect.')
  })

  it('affiche un lien expiré comme expiré, pas comme inconnu (410)', () => {
    cy.exec(`cd ../backend && php artisan e2e:expire-link ${token}`)

    cy.intercept('GET', '**/api/links/*').as('show')
    cy.visit(`/l/${token}`)

    cy.wait('@show').then(({ response }) => {
      expect(response?.statusCode).to.eq(410)
    })

    cy.contains("Ce lien a expiré : le fichier n'est plus disponible.")
    cy.contains('Ce lien de téléchargement est invalide.').should('not.exist')
    cy.get('#download-password').should('not.exist')
  })
})
