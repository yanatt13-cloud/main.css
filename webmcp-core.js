(() => {
  "use strict";

  const API_URL = "/webmcp/api.php";
  const readOnly = {
    readOnlyHint: true,
    consequentialHint: false,
    untrustedContentHint: false
  };

  async function apiGet(action, signal, params = {}) {
    const url = new URL(API_URL, window.location.origin);
    url.searchParams.set("action", action);

    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        url.searchParams.set(key, String(value));
      }
    });

    const response = await fetch(url, {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
      credentials: "same-origin",
      signal
    });

    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error(`La risposta API non è JSON valido (HTTP ${response.status}).`);
    }

    if (!response.ok || data.ok === false) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
  }

  function createDefinitions() {
    return [
      {
        name: "get_company_info",
        description: "Consulta le informazioni pubbliche, i contatti e i riferimenti di WEB iDEA.",
        inputSchema: {
          type: "object",
          properties: {},
          additionalProperties: false
        },
        execute: async (_input, { signal } = {}) => {
          const result = await apiGet("company", signal);
          return result.data;
        },
        annotations: readOnly
      },
      {
        name: "get_services",
        description: "Consulta il catalogo dei servizi offerti da WEB iDEA.",
        inputSchema: {
          type: "object",
          properties: {},
          additionalProperties: false
        },
        execute: async (_input, { signal } = {}) => {
          const result = await apiGet("services", signal);
          return result.data;
        },
        annotations: readOnly
      },
      {
        name: "get_products",
        description: "Consulta il catalogo dei prodotti software e delle soluzioni proprietarie WEB iDEA.",
        inputSchema: {
          type: "object",
          properties: {},
          additionalProperties: false
        },
        execute: async (_input, { signal } = {}) => {
          const result = await apiGet("products", signal);
          return result.data;
        },
        annotations: readOnly
      },
      {
        name: "get_product_info",
        description: "Consulta caratteristiche, disponibilità, prezzi e collegamenti di un prodotto WEB iDEA.",
        inputSchema: {
          type: "object",
          properties: {
            product: {
              type: "string",
              enum: ["NeuroPitch™", "NeuroSAVi™", "NeuroReport™"],
              description: "Nome esatto del prodotto da consultare."
            }
          },
          required: ["product"],
          additionalProperties: false
        },
        execute: async ({ product }, { signal } = {}) => {
          const result = await apiGet("product_info", signal, { name: product });
          return result.data;
        },
        annotations: readOnly
      },
      {
        name: "get_service_pricing",
        description: "Consulta i prezzi pubblicati per servizi e prodotti. I prezzi non presenti nel catalogo non devono essere dedotti.",
        inputSchema: {
          type: "object",
          properties: {
            service: {
              type: "string",
              enum: [
                "all",
                "Siti Web",
                "E-commerce",
                "SEO",
                "GEO",
                "Manutenzione e Assistenza",
                "Digital Marketing & Social",
                "Grafica e Branding",
                "NeuroSAVi™",
                "NeuroReport™",
                "GEO Audit",
                "NeuroPitch™"
              ],
              description: "Servizio o prodotto per cui filtrare i prezzi; usa all per l'elenco completo."
            }
          },
          additionalProperties: false
        },
        execute: async ({ service = "all" } = {}, { signal } = {}) => {
          const params = service === "all" ? {} : { service };
          const result = await apiGet("pricing", signal, params);

          if (!result || !Array.isArray(result.items)) {
            throw new Error("Formato della risposta API pricing non valido.");
          }

          return {
            currency: result.currency || "EUR",
            service_filter: result.service_filter || service,
            items: result.items,
            total: result.items.length,
            note: result.note || "I prezzi pubblicati sono indicativi; per i servizi su misura è necessario un preventivo personalizzato."
          };
        },
        annotations: readOnly
      }
    ];
  }

  let registrationPromise = null;
  let registrationState = {
    supported: false,
    registered: 0,
    errors: []
  };

  async function registerTools() {
    const modelContext = document.modelContext;

    if (!modelContext || typeof modelContext.registerTool !== "function") {
      registrationState = {
        supported: false,
        registered: 0,
        errors: []
      };
      return registrationState;
    }

    registrationState = {
      supported: true,
      registered: 0,
      errors: []
    };

    const controller = new AbortController();

    for (const definition of createDefinitions()) {
      try {
        await modelContext.registerTool(definition, {
          signal: controller.signal
        });
        registrationState.registered += 1;
      } catch (error) {
        registrationState.errors.push({
          tool: definition.name,
          message: error?.message || String(error)
        });
      }
    }

    return registrationState;
  }

  function ensureRegistered() {
    if (!registrationPromise) {
      registrationPromise = registerTools();
    }
    return registrationPromise;
  }

  async function getStatus() {
    const state = await ensureRegistered();
    const modelContext = document.modelContext;
    let visibleCount = state.registered;

    if (
      modelContext &&
      typeof modelContext.getTools === "function"
    ) {
      try {
        const tools = await modelContext.getTools();
        visibleCount = tools.filter(
          (tool) => tool.origin === window.location.origin
        ).length;
      } catch {
        // Keep the registration count if getTools() is unavailable.
      }
    }

    return {
      supported: state.supported,
      registered: state.registered,
      visibleCount,
      errors: [...state.errors]
    };
  }

  // Public, read-only status interface for the optional demo page.
  window.WEBIDEAWebMCP = Object.freeze({
    ready: ensureRegistered(),
    getStatus
  });

  // Registration is independent of DOMContentLoaded and page markup.
  ensureRegistered().catch((error) => {
    registrationState.errors.push({
      tool: "registration",
      message: error?.message || String(error)
    });
  });
})();
