const { defineConfig } = require("cypress");

module.exports = defineConfig({
  e2e: {
    baseUrl: 'http://127.0.0.1:8000/',

    viewportWidth: 1920,
    viewportHeight: 1200,
    video: true,

    setupNodeEvents(on, config) {
    },
  },
});