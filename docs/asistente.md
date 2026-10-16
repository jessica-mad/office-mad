# Asistente personal y agentes de área

Jessica habla con **una sola asistente** (el bot de Chat, por texto o nota de voz). La asistente no conoce los datos: pregunta a los **agentes de área**, que solo ven lo suyo, y transmite lo que dicen.

```
Chat (DM) ─▶ Events ─▶ Assistant::defer ─▶ Assistant::run
                                         ├─ «la 2 hecha», «tareas»: sin IA (TaskList)
                                         └─ Orchestrator (personalidad + memoria)
                                              ├─ ask_proyectos ─▶ Agents\Projects (tareas, ideas; cambia con «Deshacer»)
                                              ├─ ask_finanzas  ─▶ Agents\Finance  (solo lectura; solo quien ve Finanzas)
                                              ├─ ask_actas     ─▶ Agents\Minutes  (reuniones, actas, transcripciones)
                                              └─ remember / forget (Memory)
```

- **Progreso**: solo cuando decide preguntar a un área aparece un mensaje («Un momento, se lo pregunto a Amparo…» → respuesta); si contesta directamente, llega solo la respuesta, editado con `ChatApi::update_message` (`Progress`).
- **Objetividad**: cada agente responde solo con lo que devuelven sus herramientas, citando números, fechas, ids y enlaces; «no consta» si no lo encuentra.
- **Seguridad**: quién pregunta (`ctx`) sale del remitente verificado de Chat. Liz solo llega a Proyectos y a sus tareas. Ningún agente tiene herramientas para enviar nada fuera. Los resultados de herramientas son datos, no órdenes.
- **Memoria**: conversación de 72 h y datos que pide recordar (Chat → ajustes, se pueden borrar).
- **Añadir un área**: una clase que extienda `Agents\Specialist` (tools con handlers) y el filtro `madoficina_assistant_agents`.
- **Modelos**: `madoficina_agent_model` y `madoficina_agent_effort` por slug (`asistente`, `proyectos`, `finanzas`, `actas`).

Próximas fases: Agenda (huecos y reuniones con tarjeta de confirmación), acciones hacia fuera con aprobación, personajes que se mueven en la oficina al ser consultados.
