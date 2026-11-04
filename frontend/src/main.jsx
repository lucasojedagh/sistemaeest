// Punto de entrada de la aplicacion. Renderiza el componente App
// envuelto en BrowserRouter, AuthProvider y StrictMode.
// FILE: src/main.jsx
import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { AuthProvider } from "./modulos/auth/AuthContext";
import { activarSincronizacionEntrePestanas } from "./compartido/lib/pageCache";
import "./index.css";
import App from "./App.jsx";

// Si otra pestaña del mismo navegador invalida copias (porque guardó algo),
// se descarta la copia en memoria. La pantalla visible se actualiza sola al
// volver a ella (cada lista revalida al recuperar el foco).
activarSincronizacionEntrePestanas();

// Limpia claves huérfanas de versiones anteriores (miCurso, borradores, modo rápido)
try {
  localStorage.removeItem("miCurso:v1");
  localStorage.removeItem("tomar:modo-rapido");
  // borradores viejos de asistencia
  Object.keys(localStorage).forEach((k) => {
    if (k.startsWith("borrador-asistencia:v1:")) localStorage.removeItem(k);
  });
} catch (e) { void e; }

// Monta la aplicacion React en el elemento root con las providers necesarias
ReactDOM.createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <App />
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>
);
