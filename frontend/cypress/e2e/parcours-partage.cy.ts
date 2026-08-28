// Parcours critique mono-compte : inscription, téléversement, téléchargement
// public, gestion dans « Mes fichiers », déconnexion. Cypress vide
// localStorage entre les tests : ce test démarre donc déconnecté.

const FIXTURE = 'rapport-e2e.txt'
const FIXTURE_SIZE_BYTES = 69
const FIXTURE_SIZE_LABEL = '69 o'
const PASSWORD = 'Passer-e2e-1'

function uniqueEmail(): string {
  return `e2e+${Date.now()}@example.test`
}

describe('Parcours de partage de fichier', () => {
  it('inscription, téléversement, téléchargement, gestion et déconnexion', () => {
    const email = uniqueEmail()

    // 1. Inscription
    cy.visit('/register')
    cy.get('#register-email').type(email)
    cy.get('#register-password').type(PASSWORD)
    cy.get('#register-password-confirmation').type(PASSWORD)
    cy.contains('button', 'Créer mon compte').click()

    cy.location('pathname').should('eq', '/')
    cy.contains('button', 'Se déconnecter').should('be.visible')

    // 2. Téléversement
    cy.contains('h1', 'Veux-tu partager un fichier ?')
    cy.get('button[aria-label="Téléverser un fichier"]').click()

    cy.get('#upload-file').selectFile(`cypress/fixtures/${FIXTURE}`)
    cy.contains('button', 'Téléverser').click()

    cy.contains('Félicitations, ton fichier est en ligne !')

    cy.get('.upload-link')
      .invoke('attr', 'href')
      .then((href) => {
        const token = (href ?? '').split('/').filter(Boolean).pop()

        // 3. Lien public : métadonnées, puis téléchargement
        cy.visit(`/l/${token}`)

        cy.contains('h1', 'Télécharger un fichier')
        cy.contains('.download-file-name', FIXTURE)
        cy.contains('.download-file-meta', FIXTURE_SIZE_LABEL)

        cy.intercept('POST', '**/api/links/*/download').as('download')
        cy.contains('button', 'Télécharger').click()

        cy.wait('@download').then(({ response }) => {
          expect(response?.statusCode).to.eq(200)
          expect(response?.headers['content-disposition']).to.include(FIXTURE)
          expect(String(response?.headers['content-length'])).to.eq(String(FIXTURE_SIZE_BYTES))
        })

        cy.contains('Le téléchargement a démarré.')
      })

    // 4. « Mes fichiers »
    cy.visit('/mon-espace')
    cy.contains('h1', 'Mes fichiers')
    cy.contains('.file-row-name', FIXTURE)

    cy.contains('.file-row', FIXTURE).contains('button', 'Supprimer').click()
    cy.get('dialog[open]').contains('button', 'Supprimer').click()

    cy.contains('Aucun fichier à afficher.')

    // 5. Déconnexion
    cy.contains('button', 'Se déconnecter').click()

    cy.location('pathname').should('eq', '/')
    cy.contains('a', 'Se connecter').should('be.visible')
  })
})
