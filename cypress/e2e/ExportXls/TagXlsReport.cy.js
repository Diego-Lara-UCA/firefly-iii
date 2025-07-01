describe('Tag report', () => {
  it('Debería de generar un report de tag', () => {
    cy.visit('http://127.0.0.1:8000/')
    cy.viewport(1920, 1200);
    cy.get('input[name="email"]').type('kevin@gmail.com');
    cy.get('input[name="password"]').type('7kAgGmGBCK5dEvPH');
    cy.get('button[type="submit"]').click();

    cy.contains('a', 'Reports').click();

    cy.get('#inputReportType').select('tag');
    cy.contains('button', 'None selected').click();
    cy.contains('label', 'Select all').click();
    cy.contains('button', 'All selected').click();
    cy.contains('a', 'July 2025').click();
    cy.get('button.multiselect.dropdown-toggle').eq(1).click();
    cy.contains('label', 'test').click();
    cy.contains('button', 'test').click();
    cy.get('button[type="submit"]').contains('Submit').click();

    cy.wait(3000)
    cy.contains('button', 'Export').click();
    cy.get('#exportXlsLink').click();

    cy.contains('div', 'El informe XLS se está descargando.').should('be.visible');
    });
  
})