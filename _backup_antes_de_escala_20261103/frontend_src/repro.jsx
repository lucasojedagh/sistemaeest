// Archivo temporal de diagnostico: monta los modales reales de alumnos
// sin backend ni login para observar la animacion de apertura y cierre.
import React, { useState } from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { AuthProvider } from "./modulos/auth/AuthContext";
import { ToastProvider } from "./compartido/componentes/Common/Toast";
import { UndoProvider } from "./compartido/lib/deshacer.jsx";
import AlumnoDetalle from "./modulos/alumnos/AlumnoDetalle";
import HorarioAlumnoModal from "./modulos/alumnos/HorarioAlumnoModal";
import "./index.css";

// Stub de red: el repro no debe pegarle al backend real (ni provocar un 401
// que redirija a /login). Devuelve respuestas vacias y validas.
const realFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
  const url = typeof input === "string" ? input : input?.url || "";
  if (url.includes("/api/") || url.includes("localhost:8000")) {
    let body = {};
    if (url.includes("/divisiones")) body = { 1: ["1", "2"], 2: ["1", "2"], 3: ["1", "2"] };
    else if (url.includes("/horario")) body = { effective: [], base: [], overrides: [], recursando_overlay: [], intensificacion_overlay: [] };
    else if (url.includes("/calificaciones")) body = { notas_por_materia: {} };
    else if (url.includes("/materias")) body = [];
    return Promise.resolve(
      new Response(JSON.stringify(body), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    );
  }
  return realFetch(input, init);
};

const ALUMNO = {
  id: 999,
  nombre: "Ana",
  apellido: "Perez",
  genero: "F",
  dni: "30111222",
  fechaNacimiento: "2008-05-10",
  fecha_nacimiento: "2008-05-10",
  anio: "1 1",
  anio_num: 1,
  division: "1",
  grupo: "A",
  estado: "Activo",
  curso: { anio: "1", division: "1" },
  direcciones: [{ titular: "Madre", direccion: "Calle 123" }],
  telefonos: [{ titular: "Madre", telefono: "123456" }],
  responsables: [
    { nombre: "Maria", apellido: "Perez", parentesco: "Madre", convive: true, tutor_legal: true },
  ],
  recursando: [],
  intensificacion: [],
  observaciones: "",
  tiene_ppi: false,
  tiene_ae: false,
  especialidad: null,
};

function Repro() {
  const [alumno, setAlumno] = useState(null);
  const [horario, setHorario] = useState(null);
  return (
    <div style={{ padding: 40 }}>
      <button id="btn-detalle" onClick={() => setAlumno(ALUMNO)}>
        Editar ficha
      </button>{" "}
      <button id="btn-horario" onClick={() => setHorario(ALUMNO)}>
        Cronograma
      </button>
      <AlumnoDetalle
        alumno={alumno}
        setAlumno={setAlumno}
        actualizarAlumno={() => {}}
        eliminarAlumno={() => {}}
        onOpenCronograma={() => {}}
      />
      <HorarioAlumnoModal
        visible={!!horario}
        alumno={horario}
        onClose={() => setHorario(null)}
        onGuardar={() => {}}
      />
    </div>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <ToastProvider>
          <UndoProvider>
            <Repro />
          </UndoProvider>
        </ToastProvider>
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>
);
