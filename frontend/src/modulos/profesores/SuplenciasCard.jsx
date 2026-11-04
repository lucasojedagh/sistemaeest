import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, ArrowRight, Users } from 'lucide-react';
import { apiGet, apiPost, apiDelete } from '../../compartido/lib/api';
import { mensajeAmable } from '../../compartido/lib/errores';
import { cursoLabel } from '../../compartido/lib/cursos';
import useAviso from '../../compartido/componentes/modals/useAviso';
import './SuplenciasCard.css';

// Componente: SuplenciasCard
// Proposito: muestra y administra la cadena de suplencias de un profesor:
//   - "Lo cubren": quienes lo reemplazan a el (con materia y motivo).
//   - "Cubre a": a quienes reemplaza el.
//   - Cadena completa hacia abajo (A -> B -> C).
// En modo edicion permite agregar eslabones (persona existente + materia de
// las asignaciones del titular) y quitarlos. Cada cambio avisa al padre
// para que refresque las etiquetas de la lista.
// Props:
//   profesorId   - Id del profesor de la ficha.
//   asignaciones - Asignaciones propias [{anio, division, materia}].
//   soloLectura  - true: solo muestra, sin formularios ni borrado.
//   onCambio     - Callback tras agregar/quitar (para refrescar la lista).

const nombreCompleto = (p) => {
  if (!p) return '—';
  const n = `${p.apellido || ''}, ${p.nombre || ''}`.trim().replace(/^, |, $/g, '');
  return n || p.email || `#${p.id}`;
};

const etiquetaMateria = (a) => {
  const curso = cursoLabel({ anio: a.anio, division: a.division || null });
  return `${a.materia || '—'} · ${curso}`;
};

export default function SuplenciasCard({ profesorId, asignaciones = [], soloLectura = false, onCambio }) {
  const [mostrarAviso, , avisoModal] = useAviso();
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(false);
  const [profesores, setProfesores] = useState([]);

  // Formulario de alta
  const [direccion, setDireccion] = useState('cubierto'); // cubierto | cubre
  const [personaId, setPersonaId] = useState('');
  const [materiaSel, setMateriaSel] = useState('');
  const [motivo, setMotivo] = useState('');
  const [asigTitular, setAsigTitular] = useState([]);
  const [cargandoAsig, setCargandoAsig] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const [materiasPorAnio, setMateriasPorAnio] = useState({});

  const cargar = useCallback(async () => {
    if (!profesorId) return;
    setCargando(true);
    try {
      const r = await apiGet(`/profesores/${profesorId}/suplencias`);
      setDatos(r);
    } catch {
      setDatos({ otorga: [], recibe: [], cadena: [] });
    } finally {
      setCargando(false);
    }
  }, [profesorId]);

  useEffect(() => {
    cargar();
    apiGet('/profesores?per_page=2000')
      .then((r) => setProfesores(Array.isArray(r) ? r : (r?.data ?? [])))
      .catch(() => setProfesores([]));
  }, [cargar]);

  // Al elegir a quién cubrir, traer sus asignaciones para elegir la materia.
  useEffect(() => {
    if (direccion !== 'cubre' || !personaId) { setAsigTitular([]); return; }
    let vivo = true;
    setCargandoAsig(true);
    apiGet(`/profesores/${personaId}`)
      .then((p) => { if (vivo) setAsigTitular(p?.asignaciones || []); })
      .catch(() => { if (vivo) setAsigTitular([]); })
      .finally(() => { if (vivo) setCargandoAsig(false); });
    return () => { vivo = false; };
  }, [direccion, personaId]);

  // Materias candidatas según la dirección elegida.
  const candidatas = useMemo(() => {
    const base = direccion === 'cubierto' ? (asignaciones || []) : asigTitular;
    return (base || [])
      .map((a, i) => ({
        key: `${a.anio}|${a.division || ''}|${a.materia || ''}|${i}`,
        anio: a.anio,
        division: a.division || null,
        materia: (a.materia || '').trim(),
      }))
      .filter((a) => a.anio && a.materia);
  }, [direccion, asignaciones, asigTitular]);

  const resolverMateriaId = async (anio, nombre) => {
    const cache = materiasPorAnio[anio];
    const lista = cache || await apiGet(`/anios/${anio}/materias`).catch(() => []);
    if (!cache && Array.isArray(lista)) {
      setMateriasPorAnio((prev) => ({ ...prev, [anio]: lista }));
    }
    const arr = Array.isArray(lista) ? lista : [];
    const norm = (s) => String(s || '').trim().toLowerCase();
    const m = arr.find((x) => norm(x.nombre) === norm(nombre));
    return m ? m.id : null;
  };

  const agregar = async () => {
    if (!personaId) {
      await mostrarAviso({ icon: 'warning', title: 'Falta la persona', text: 'Elegí qué profesor va a cubrir o a ser cubierto.' });
      return;
    }
    const cand = candidatas.find((c) => c.key === materiaSel);
    if (!cand) {
      await mostrarAviso({ icon: 'warning', title: 'Falta la materia', text: 'Elegí en qué materia va a cubrir.' });
      return;
    }
    const materiaId = await resolverMateriaId(cand.anio, cand.materia).catch(() => null);
    if (!materiaId) {
      await mostrarAviso({ icon: 'error', title: 'No se encontró la materia', text: `"${cand.materia}" ya no existe para ese año. Actualizá las asignaciones.` });
      return;
    }

    // El eslabón siempre se carga del lado del titular.
    const titularId = direccion === 'cubierto' ? profesorId : Number(personaId);
    const suplenteId = direccion === 'cubierto' ? Number(personaId) : profesorId;

    setGuardando(true);
    try {
      await apiPost(`/profesores/${titularId}/suplencias`, {
        suplente_id: suplenteId,
        anio: cand.anio,
        division: cand.division,
        materia_id: materiaId,
        motivo: motivo.trim() || null,
      });
      setPersonaId('');
      setMateriaSel('');
      setMotivo('');
      await cargar();
      onCambio?.();
    } catch (e) {
      await mostrarAviso({ icon: 'error', title: 'No se pudo guardar', text: mensajeAmable(e, 'Revisá los datos e intentá de nuevo.') });
    } finally {
      setGuardando(false);
    }
  };

  const quitar = async (id) => {
    try {
      await apiDelete(`/profesores/suplencias/${id}`);
      await cargar();
      onCambio?.();
    } catch (e) {
      await mostrarAviso({ icon: 'error', title: 'No se pudo quitar', text: mensajeAmable(e, 'Intentá de nuevo.') });
    }
  };

  if (!profesorId) return null;

  const otorga = datos?.otorga || [];
  const recibe = datos?.recibe || [];
  const cadena = datos?.cadena || [];
  const opcionesPersonas = (profesores || [])
    .filter((p) => Number(p.id) !== Number(profesorId))
    .map((p) => ({ value: String(p.id), label: nombreCompleto(p) }))
    .sort((a, b) => a.label.localeCompare(b.label, 'es'));

  const fila = (s, lado) => (
    <li key={s.id} className="sup-fila">
      <div className="sup-fila-info">
        <span className="sup-fila-nombre">{s.otro_nombre}</span>
        <span className="sup-fila-detalle">
          {s.materia} · {cursoLabel({ anio: s.anio, division: s.division })}
          {s.motivo ? ` · ${s.motivo}` : ''}
        </span>
      </div>
      {!soloLectura && (
        <button
          type="button"
          onClick={() => quitar(s.id)}
          className="sup-del"
          title={lado === 'otorga' ? 'Quitar esta suplencia' : 'Dejar de cubrir'}
          aria-label="Quitar suplencia"
        >
          <Trash2 size={13} />
        </button>
      )}
    </li>
  );

  return (
    <div className="det-card">
      <h3 className="det-card-title det-card-title--blue">Suplencias</h3>

      {cargando ? (
        <p className="det-hint">Cargando suplencias…</p>
      ) : (
        <>
          <div className="sup-listas">
            <div className="sup-col">
              <h4 className="sup-subtitulo">Lo cubren</h4>
              {otorga.length === 0 ? (
                <p className="det-hint">{soloLectura ? 'Nadie lo cubre.' : 'Todavía no tiene suplente. Agregá uno abajo.'}</p>
              ) : (
                <ul className="sup-lista">{otorga.map((s) => fila(s, 'otorga'))}</ul>
              )}
            </div>
            <div className="sup-col">
              <h4 className="sup-subtitulo">Cubre a</h4>
              {recibe.length === 0 ? (
                <p className="det-hint">No cubre a nadie.</p>
              ) : (
                <ul className="sup-lista">{recibe.map((s) => fila(s, 'recibe'))}</ul>
              )}
            </div>
          </div>

          {cadena.length > 0 && (
            <div className="sup-cadena" aria-label="Cadena de suplencias">
              <Users size={14} />
              {cadena.map((nivel, i) => (
                <React.Fragment key={i}>
                  {i > 0 && <ArrowRight size={13} className="sup-cadena-flecha" />}
                  <span className="sup-cadena-nivel" title={`Nivel ${i + 1}`}>
                    {nivel.map((p) => p.nombre).join(', ')}
                  </span>
                </React.Fragment>
              ))}
            </div>
          )}

          {!soloLectura && (
            <div className="sup-form">
              <div className="sup-direccion" role="group" aria-label="Dirección de la suplencia">
                <label className="sup-radio">
                  <input
                    type="radio" name="sup-direccion" checked={direccion === 'cubierto'}
                    onChange={() => { setDireccion('cubierto'); setPersonaId(''); setMateriaSel(''); }}
                  />
                  Será cubierto por…
                </label>
                <label className="sup-radio">
                  <input
                    type="radio" name="sup-direccion" checked={direccion === 'cubre'}
                    onChange={() => { setDireccion('cubre'); setPersonaId(''); setMateriaSel(''); }}
                  />
                  Cubrirá a…
                </label>
              </div>

              <div className="sup-form-grid">
                <label className="sup-field">
                  <span className="det-field-label">Profesor</span>
                  <select
                    value={personaId}
                    onChange={(e) => { setPersonaId(e.target.value); setMateriaSel(''); }}
                    className="det-input"
                  >
                    <option value="">Seleccionar…</option>
                    {opcionesPersonas.map((o) => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </select>
                </label>

                <label className="sup-field">
                  <span className="det-field-label">Materia que cubre</span>
                  <select
                    value={materiaSel}
                    onChange={(e) => setMateriaSel(e.target.value)}
                    className="det-input"
                    disabled={direccion === 'cubre' && (cargandoAsig || !personaId)}
                  >
                    <option value="">
                      {direccion === 'cubre' && !personaId
                        ? 'Primero elegí al profesor…'
                        : (candidatas.length ? 'Seleccionar…' : 'Sin materias para cubrir')}
                    </option>
                    {candidatas.map((c) => (
                      <option key={c.key} value={c.key}>{etiquetaMateria(c)}</option>
                    ))}
                  </select>
                </label>

                <label className="sup-field">
                  <span className="det-field-label">Motivo (opcional)</span>
                  <input
                    type="text"
                    value={motivo}
                    onChange={(e) => setMotivo(e.target.value)}
                    placeholder="Ej.: licencia, accidente"
                    maxLength={250}
                    className="det-input"
                  />
                </label>

                <div className="sup-add-wrap">
                  <button
                    type="button"
                    onClick={agregar}
                    disabled={guardando}
                    className="det-asig-add"
                  >
                    <Plus size={16} /> {guardando ? 'Guardando…' : 'Agregar'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </>
      )}
      {avisoModal}
    </div>
  );
}
