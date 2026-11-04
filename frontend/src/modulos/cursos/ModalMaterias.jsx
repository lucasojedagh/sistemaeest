import { useState, useEffect, useMemo, useRef } from 'react';
import { X, Plus, Trash2, Pencil, Check, Layers } from 'lucide-react';
import { apiPut } from '../../compartido/lib/api';
import useConfirmAction from '../../compartido/hooks/useConfirmAction';
import usePasswordPrompt from '../../compartido/componentes/modals/usePasswordPrompt';
import { useDeshacerAviso } from '../../compartido/lib/deshacer.jsx';
import useAviso from '../../compartido/componentes/modals/useAviso';
import './ModalMaterias.css';

const isAbsolute = (u) => /^https?:\/\//i.test(u);
const joinUrl = (base, path) => {
  const cleanBase = (base || '').replace(/\/+$/g, '');
  const cleanPath = String(path || '').replace(/^\/+/g, '');
  if (isAbsolute(cleanBase)) return `${cleanBase}/${cleanPath}`;
  const relBase = `/${cleanBase.replace(/^\/+/g, '')}`;
  return `${relBase}/${cleanPath}`;
};

const TIPO_LABELS = { aula: 'Aula', taller: 'Taller', especialidad: 'Especialidad' };

const ESPEC_LIST = [
  { value: 'informatica', label: 'Informática' },
  { value: 'mmo', label: 'Maestro mayor de obras' },
  { value: 'electromecanica', label: 'Electromecánica' },
  { value: 'quimica', label: 'Química' },
];

const tiposParaAnio = (anio) => (Number(anio) >= 4 ? ['aula', 'especialidad'] : ['aula', 'taller']);

export default function ModalMaterias({ visible, onClose, anios = [], materiasPorAnio = {}, setMaterias, loading }) {
  const readOnly = !setMaterias;
  const [anioSel, setAnioSel] = useState(null);
  const [localMaterias, setLocalMaterias] = useState([]);
  // Snapshot de las materias tal como vinieron del servidor al abrir el año:
  // sirve para deshacer el último guardado.
  const materiasOriginalRef = useRef([]);
  const [nuevoNombre, setNuevoNombre] = useState('');
  const [nuevoTipo, setNuevoTipo] = useState('aula');
  const [nuevaEspecialidad, setNuevaEspecialidad] = useState('');
  const [editId, setEditId] = useState(null);
  const [editValor, setEditValor] = useState('');

  // Año al que corresponde el snapshot actual (para no re-capturarlo con cada
  // cambio de materiasPorAnio, que ocurre tras CADA guardado).
  const snapshotAnioRef = useRef(null);

  const [confirmAction, confirmModal] = useConfirmAction();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  // Evita doble confirmación (el segundo clic huérfana el primero).
  const [guardando, setGuardando] = useState(false);
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [mostrarAviso, , avisoModal] = useAviso();

  const listaAnios = useMemo(() => {
    const lista = (anios || [])
      .map((a) => (a && typeof a === 'object' ? Number(a.id ?? a.anio) : Number(a)))
      .filter((n) => Number.isFinite(n))
      .sort((a, b) => a - b);
    return lista;
  }, [anios]);

  const curso = useMemo(() => {
    if (anioSel == null) return '';
    const idx = Number(anioSel) - 1;
    return ['1°', '2°', '3°', '4°', '5°', '6°', '7°'][idx] || `${anioSel}°`;
  }, [anioSel]);

  const anioMayor = Number(anioSel) >= 4;

  useEffect(() => {
    if (!visible) return;
    setAnioSel((prev) => {
      if (prev != null) return prev;
      return listaAnios.length ? listaAnios[0] : null;
    });
  }, [visible, listaAnios]);

  useEffect(() => {
    if (!visible) return;
    // Nueva sesión de apertura del modal: se vuelve a capturar el snapshot
    // la primera vez que se entra a cada año.
    snapshotAnioRef.current = null;
  }, [visible]);

  useEffect(() => {
    if (!visible || anioSel == null) return;
    const lista = (materiasPorAnio?.[anioSel] || []).map((m) => ({
      id: typeof m.id !== 'undefined' ? m.id : `temp_${Math.random().toString(36).slice(2)}`,
      nombre: m.nombre,
      tipo: m.tipo || 'aula',
      especialidad: m.tipo === 'especialidad' ? (m.especialidad || '') : '',
    }));
    setLocalMaterias(lista);
    // Snapshot SOLO la primera vez que se entra al año: si se re-capturara en
    // cada cambio de materiasPorAnio, tras un guardado incluiría las materias
    // recién guardadas y el siguiente deshacer no las llegaría a revertir.
    if (snapshotAnioRef.current !== anioSel) {
      materiasOriginalRef.current = lista;
      snapshotAnioRef.current = anioSel;
    }
    setNuevoTipo(tiposParaAnio(anioSel)[0]);
    setNuevaEspecialidad('');
    setNuevoNombre('');
    setEditId(null);
    setEditValor('');
  }, [visible, anioSel, materiasPorAnio]);

  const normalizar = (v) => (v || '').trim().replace(/\s+/g, ' ').toLowerCase();

  const nombresExistentes = useMemo(() => {
    const set = new Set();
    localMaterias.forEach((m) => set.add(normalizar(m.nombre) + '|' + (m.tipo || 'aula')));
    return set;
  }, [localMaterias]);

  const cambiarAnio = (e) => {
    setAnioSel(Number(e.target.value));
  };

  const agregarMateria = async () => {
    const nombre = nuevoNombre.trim().replace(/\s+/g, ' ');
    if (!nombre) return;
    const tipo = nuevoTipo || 'aula';
    const clave = normalizar(nombre) + '|' + tipo;
    if (nombresExistentes.has(clave)) {
      await mostrarAviso({
        icon: 'error',
        title: 'Materia duplicada',
        text: `Ya existe "${nombre}" en la sección ${TIPO_LABELS[tipo] || tipo}.`,
      });
      return;
    }
    const especialidad = tipo === 'especialidad' && anioMayor ? nuevaEspecialidad : '';
    setLocalMaterias([
      ...localMaterias,
      { id: `temp_${Date.now()}_${Math.random().toString(36).slice(2)}`, nombre, tipo, especialidad },
    ]);
    setNuevoNombre('');
    setNuevaEspecialidad('');
  };

  const cambiarTipo = (id, tipo) => {
    setLocalMaterias((prev) =>
      prev.map((m) => (m.id === id ? { ...m, tipo, especialidad: tipo === 'especialidad' && anioMayor ? m.especialidad : '' } : m))
    );
  };

  const cambiarEspecialidad = (id, especialidad) => {
    setLocalMaterias((prev) => prev.map((m) => (m.id === id ? { ...m, especialidad } : m)));
  };

  const confirmarEditar = async (id) => {
    const valor = editValor.trim().replace(/\s+/g, ' ');
    if (!valor) {
      setEditId(null);
      return;
    }
    const item = localMaterias.find((m) => m.id === id);
    const tipoItem = item?.tipo || 'aula';
    const duplicado = localMaterias.some(
      (m) => m.id !== id && normalizar(m.nombre) === normalizar(valor) && (m.tipo || 'aula') === tipoItem
    );
    if (duplicado) {
      await mostrarAviso({
        icon: 'error',
        title: 'Nombre duplicado',
        text: `Ya existe "${valor}" en esta sección.`,
      });
      return;
    }
    setLocalMaterias((prev) => prev.map((m) => (m.id === id ? { ...m, nombre: valor } : m)));
    setEditId(null);
    setEditValor('');
  };

  const eliminarMateria = (id) => {
    setLocalMaterias((prev) => prev.filter((m) => m.id !== id));
  };

  const armarPayload = (lista) =>
    lista.map((m) => ({
      id: typeof m.id === 'number' ? m.id : undefined,
      nombre: m.nombre,
      tipo: m.tipo || 'aula',
      especialidad: m.tipo === 'especialidad' ? m.especialidad || null : null,
    }));

  // Compara el listado actual contra el que se cargo, para saber si hay
// algo que guardar. Ignora el orden (el backend no lo usa como criterio)
// y compara nombre/tipo/especialidad ya recortados.
const hayCambiosPendientes = (lista, original) => {
  const clave = (m) =>
    [
      typeof m.id === 'number' ? m.id : '',
      (m.nombre || '').trim().replace(/\s+/g, ' '),
      m.tipo || 'aula',
      m.tipo === 'especialidad' ? m.especialidad || '' : '',
    ].join('|');
  const a = (original || []).map(clave).sort();
  const b = (lista || []).map(clave).sort();
  return a.length !== b.length || a.some((v, i) => v !== b[i]);
};

const guardarMaterias = async () => {
    if (readOnly || guardando) return;

    // Si no se toco nada, se avisa acá y se sale: asi no se pide la contraseña
    // ni el motivo para un guardado que no iba a cambiar nada.
    if (!hayCambiosPendientes(localMaterias, materiasOriginalRef.current)) {
      await mostrarAviso({
        icon: 'info',
        title: 'No hay nada que guardar',
        text: 'No cambiaste ninguna materia de este año.',
      });
      return;
    }

    // Reemplazo masivo: el servidor exige contraseña reciente.
    if (!(await confirmarContrasena())) return;
    const payload = armarPayload(localMaterias);

    setGuardando(true);
    confirmAction(
      `Describí el motivo por el que modificás las materias de ${curso}. Podés omitir el detalle si no aplica.`,
      async (detalles) => {
        const data = await apiPut(joinUrl(`/anios/${anioSel}`, 'materias'), {
          materias: payload,
          _detalles: detalles,
        });
        setMaterias(anioSel, data);
        const originales = materiasOriginalRef.current || [];
        // Deshacer: restaura las materias que había antes del guardado.
        ofrecerDeshacer(
          {
            etiqueta: `Materias de ${curso}`,
            deshacer: async () => {
              const revert = await apiPut(joinUrl(`/anios/${anioSel}`, 'materias'), {
                materias: armarPayload(originales),
                _detalles: 'Deshace el último guardado',
              });
              setMaterias(anioSel, revert);
              const listaRevert = originales.map((m) => ({
                id: m.id,
                nombre: m.nombre,
                tipo: m.tipo || 'aula',
                especialidad: m.tipo === 'especialidad' ? (m.especialidad || '') : '',
              }));
              setLocalMaterias(listaRevert);
              materiasOriginalRef.current = listaRevert;
            },
          },
          'Cambios guardados correctamente'
        );
      }
    ).finally(() => setGuardando(false));
  };

  const secciones = useMemo(() => {
    if (anioSel == null) return [];
    const secs = [];
    secs.push({ key: 'aula', titulo: 'Aula', items: localMaterias.filter((m) => m.tipo === 'aula') });
    if (!anioMayor) {
      secs.push({ key: 'taller', titulo: 'Taller', items: localMaterias.filter((m) => m.tipo === 'taller') });
    } else {
      ESPEC_LIST.forEach((e) =>
        secs.push({
          key: 'esp-' + e.value,
          titulo: 'Especialidad · ' + e.label,
          items: localMaterias.filter((m) => m.tipo === 'especialidad' && (m.especialidad || '') === e.value),
        })
      );
    }
    return secs;
  }, [anioSel, anioMayor, localMaterias]);

  if (!visible) return null;

  return (
    <>
      <div className="mmat-overlay">
      <div className="mmat-modal">
        <div className="mmat-header">
          <div>
            <h3 className="mmat-title">Materias del curso {curso}</h3>
            <p className="mmat-sub">
              {anioMayor
                ? 'Aula y las materias de especialidad (4° a 7°).'
                : 'Aula y Taller (1° a 3°).'}
            </p>
          </div>
          <button className="mmat-close" onClick={onClose} aria-label="Cerrar">
            <X size={20} />
          </button>
        </div>

        <div className="mmat-body">
          <div className="mmat-anio-tabs">
            {listaAnios.map((a) => (
              <button
                key={a}
                className={`mmat-anio-tab ${Number(a) === Number(anioSel) ? 'active' : ''} ${readOnly ? 'readonly' : ''}`}
                onClick={() => !readOnly && cambiarAnio({ target: { value: String(a) } })}
              >
                {['1°', '2°', '3°', '4°', '5°', '6°', '7°'][Number(a) - 1] || `${a}°`}
              </button>
            ))}
          </div>

          {loading ? (
            <div className="mmat-loading">Cargando materias…</div>
          ) : (
            <div className="mmat-secciones">
              {secciones.map((sec) => (
                <div className="mmat-section" key={sec.key}>
                  <div className={`mmat-section-title mmat-section-title--${sec.key === 'aula' ? 'aula' : sec.key === 'taller' ? 'taller' : 'especialidad'}`}>
                    <Layers size={14} />
                    <span>{sec.titulo}</span>
                    <span className="mmat-section-count">{sec.items.length}</span>
                  </div>

                  <div className="mmat-lista">
                    {sec.items.length === 0 && <div className="mmat-empty-sec">Sin materias</div>}

                    {sec.items.map((m) => {
                      const editando = editId === m.id;
                      return (
                        <div className={`mmat-materia ${editando ? 'editando' : ''}`} key={m.id}>
                          {editando ? (
                            <div className="mmat-edit-row">
                              <input
                                className="mmat-input"
                                value={editValor}
                                autoFocus
                                onChange={(e) => setEditValor(e.target.value)}
                                onKeyDown={(e) => {
                                  if (e.key === 'Enter') confirmarEditar(m.id);
                                  if (e.key === 'Escape') {
                                    setEditId(null);
                                    setEditValor('');
                                  }
                                }}
                              />
                              <button className="mmat-icon-btn ok" onClick={() => confirmarEditar(m.id)} title="Guardar">
                                <Check size={16} />
                              </button>
                            </div>
                          ) : (
                            <>
                              <span className="mmat-nombre">{m.nombre}</span>

                              {!readOnly ? (
                                <>
                                  <select
                                    className="mmat-chip"
                                    value={m.tipo}
                                    onChange={(e) => cambiarTipo(m.id, e.target.value)}
                                    title="Tipo de materia"
                                  >
                                    {tiposParaAnio(anioSel).map((t) => (
                                      <option key={t} value={t}>
                                        {TIPO_LABELS[t]}
                                      </option>
                                    ))}
                                  </select>

                                  {m.tipo === 'especialidad' && anioMayor && (
                                    <select
                                      className="mmat-chip mmat-chip--esp"
                                      value={m.especialidad || ''}
                                      onChange={(e) => cambiarEspecialidad(m.id, e.target.value)}
                                      title="Especialidad"
                                    >
                                      <option value="">Sin definir</option>
                                      {ESPEC_LIST.map((e) => (
                                        <option key={e.value} value={e.value}>
                                          {e.label}
                                        </option>
                                      ))}
                                    </select>
                                  )}

                                  <button className="mmat-icon-btn" onClick={() => { setEditId(m.id); setEditValor(m.nombre); }} title="Renombrar">
                                    <Pencil size={16} />
                                  </button>
                                  <button className="mmat-icon-btn danger" onClick={() => eliminarMateria(m.id)} title="Eliminar">
                                    <Trash2 size={16} />
                                  </button>
                                </>
                              ) : (
                                <span className={`mmat-badge mmat-badge--${m.tipo === 'especialidad' ? 'especialidad' : m.tipo}`}>
                                  {m.tipo === 'especialidad'
                                    ? ESPEC_LIST.find((e) => e.value === m.especialidad)?.label || 'Especialidad'
                                    : TIPO_LABELS[m.tipo]}
                                </span>
                              )}
                            </>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}

          {!readOnly && !loading && (
            <div className="mmat-add">
              <input
                className="mmat-input"
                placeholder="Nueva materia..."
                value={nuevoNombre}
                onChange={(e) => setNuevoNombre(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && agregarMateria()}
              />
              <select
                className="mmat-chip"
                value={nuevoTipo}
                onChange={(e) => setNuevoTipo(e.target.value)}
                title="Tipo de materia"
              >
                {tiposParaAnio(anioSel).map((t) => (
                  <option key={t} value={t}>
                    {TIPO_LABELS[t]}
                  </option>
                ))}
              </select>
              {nuevoTipo === 'especialidad' && anioMayor && (
                <select
                  className="mmat-chip mmat-chip--esp"
                  value={nuevaEspecialidad}
                  onChange={(e) => setNuevaEspecialidad(e.target.value)}
                  title="Especialidad"
                >
                  <option value="">Especialidad…</option>
                  {ESPEC_LIST.map((e) => (
                    <option key={e.value} value={e.value}>
                      {e.label}
                    </option>
                  ))}
                </select>
              )}
              <button className="mmat-add-btn" onClick={agregarMateria} title="Agregar materia">
                <Plus size={16} /> Agregar
              </button>
            </div>
          )}
        </div>

        <div className="mmat-footer">
          <button className="mmat-btn ghost" onClick={onClose}>
            {readOnly ? 'Cerrar' : 'Cancelar'}
          </button>
          {!readOnly && (
            <button className="mmat-btn primary" onClick={guardarMaterias} disabled={guardando}>
              {guardando ? 'Guardando…' : 'Guardar cambios'}
            </button>
          )}
        </div>
      </div>
    </div>
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </>
  );
}
