// ===============================
// FILE: src/components/Profesores/ListaProfesores.jsx
// ===============================
import React, { useRef, useEffect, useMemo, useState } from 'react';
import { UserRound, Eye, Pencil } from 'lucide-react';
import Avatar from '../../compartido/componentes/Common/Avatar';
import "./ListaProfesores.css";

// Componente: ListaProfesores
// Proposito: Renderiza la lista de profesores con virtualizacion
//            simple (ventana fija de 15 filas). Modo "activos" con detalle de
//            asignaciones expandibles.
// Props:
//   profesores         - Array de objetos profesor.
//   onMostrarDetalle   - Callback al hacer clic en "Ver detalle".

// Altura estimada por fila y tamanio de ventana fija para virtualizacion
const ROW_EST = 130;       // Altura aproximada de cada fila en pixeles
const WINDOW_SIZE = 15;    // Cantidad fija de filas montadas simultaneamente
const STEP_ROWS = 1;       // Actualiza el indice solo cuando cambia de fila

const ListaProfesores = ({
  profesores = [],
  soloLectura = false,         // true: sin edición de datos; cambia el rótulo del botón
  onVerFicha,                  // (prof) => void   (solo lectura)
  onMostrarDetalle,            // (prof) => void   (editar; lo manda el padre solo si el rol puede)
}) => {
  const containerRef = useRef(null);
  const rafRef = useRef(0);
  const lastRowRef = useRef(-1);

  // índice de inicio de la ventana virtual
  const [startIndex, setStartIndex] = useState(0);

  // ids expandidos (activos)
  const [expand, setExpand] = useState(() => new Set());

  // atajos
  const count = profesores.length;

  /* Configura el scroll, teclado (flechas arriba/abajo) y ResizeObserver para la virtualizacion */
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    /* Navegacion por teclado con flechas arriba/abajo */
    const onKey = (e) => {
      if (e.key === 'ArrowDown') el.scrollBy({ top: ROW_EST, behavior: 'smooth' });
      if (e.key === 'ArrowUp') el.scrollBy({ top: -ROW_EST, behavior: 'smooth' });
    };
    el.addEventListener('keydown', onKey);

    /* Actualiza el indice de inicio de la ventana virtual al hacer scroll (throttle con rAF) */
    const onScroll = () => {
      if (rafRef.current) cancelAnimationFrame(rafRef.current);
      rafRef.current = requestAnimationFrame(() => {
        const st = el.scrollTop || 0;
        const row = Math.max(0, Math.floor(st / ROW_EST));
        if (Math.abs(row - lastRowRef.current) >= STEP_ROWS) {
          lastRowRef.current = row;
          const maxStart = Math.max(0, count - WINDOW_SIZE);
          setStartIndex(Math.min(row, maxStart));
        }
      });
    };
    el.addEventListener('scroll', onScroll, { passive: true });

    // Calculo inicial
    onScroll();

    return () => {
      el.removeEventListener('keydown', onKey);
      el.removeEventListener('scroll', onScroll);
      if (rafRef.current) cancelAnimationFrame(rafRef.current);
    };
  }, [count]);

  /* Reinicia la ventana virtual al cambiar la lista (ej: al aplicar filtros) */
  useEffect(() => {
    lastRowRef.current = -1;
    setStartIndex(0);
    const el = containerRef.current;
    if (el) el.scrollTop = 0;
  }, [profesores]);

  // Agrupa asignaciones por materia => [{ materia, cursos: [...] }]
  /* Construye la etiqueta del curso incluyendo el grupo (ej.: "4 B Grp.A") */
  const buildCursoLabel = (a) => {
    if (a?.curso) return a.curso;
    const anio = a?.anio || '';
    const division = a?.division ? ` ${a.division}` : '';
    const grupo = a?.grupo ? ` Grp.${a.grupo}` : '';
    return (anio || division) ? `${anio}${division}${grupo}` : '';
  };

  /* Agrupa las asignaciones por materia y las ordena */
  const groupAsignaciones = (list = []) => {
    const map = new Map();
    for (const a of list || []) {
      const materia = (a?.materia || '').trim();
      const curso = buildCursoLabel(a);
      if (!materia && !curso) continue;
      if (!map.has(materia)) map.set(materia, []);
      if (curso) map.get(materia).push(curso);
    }
    return Array.from(map.entries()).map(([materia, cursos]) => ({ materia, cursos }));
  };

  // Estilos tarjeta/fila
  const cardClasses = 'prof-lista-card';
  const rowHover = 'prof-lista-row-hover';
  const avatarWrap = 'prof-lista-avatar';
  const avatarIcon = 'prof-lista-avatar-icon';

  /* Verifica si un profesor tiene sus asignaciones expandidas */
  const isExpanded = (id) => expand.has(id);

  /* Expande o contrae las asignaciones de un profesor */
  const toggleExpand = (id) => {
    setExpand(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  /* ======== Ventana fija de 15 elementos (virtualizacion) ======== */
  const sliceStart = startIndex;
  const sliceEnd = Math.min(count, sliceStart + WINDOW_SIZE);
  const slice = useMemo(() => profesores.slice(sliceStart, sliceEnd), [profesores, sliceStart, sliceEnd]);

  const topSpacer    = sliceStart * ROW_EST;
  const bottomSpacer = Math.max(0, (count - sliceEnd) * ROW_EST);

  return (
    <div className="prof-lista-root">
      <div className="prof-lista-fade-top" />
      <div className="prof-lista-fade-bottom" />

      <div
        ref={containerRef}
        tabIndex={0}
        className="prof-lista-scroll"
        style={{ scrollPaddingTop: '0.5rem', scrollPaddingBottom: '0.5rem' }}
      >
        <div className={`prof-lista-table-wrap ${cardClasses}`}>
          <table className="prof-lista-table">
            <tbody className="prof-lista-tbody">
              {/* Espaciador superior */}
              {topSpacer > 0 && (
                <tr aria-hidden="true">
                  <td colSpan={3} style={{ height: `${topSpacer}px` }} />
                </tr>
              )}

              {/* Filas montadas (siempre <= 15) */}
              {slice.map((p) => {
                const grupos = groupAsignaciones(p.asignaciones);
                const expanded = isExpanded(p.id);
                const visibleGrupos = expanded ? grupos : grupos.slice(0, 3);

                return (
                  <tr
                    key={p.id}
                    className={`prof-lista-row ${rowHover}`}
                    style={{ minHeight: `${ROW_EST}px` }}
                  >
                    {/* Columna principal */}
                    <td className="prof-lista-td">
                      <div className="prof-lista-cell-main">
                        <Avatar
                          id={p.user_id}
                          version={p.avatar_version}
                          className={avatarWrap}
                          titulo={`${p.apellido}, ${p.nombre}`}
                        >
                          <UserRound size={20} className={avatarIcon} />
                        </Avatar>

                        <div className="prof-lista-cell-text">
                          <h3 className="prof-lista-nombre">
                            {p.apellido}, {p.nombre}
                          </h3>
                          {p.email && (
                            <p className="prof-lista-email">{p.email}</p>
                          )}

                          {/* materias (máx 3 + botón Ver más) */}
                          {(
                            <div className="prof-lista-grupos">
                              {visibleGrupos.map((g, i) => (
                                <div
                                  key={`${p.id}-${g.materia}-${i}`}
                                  className="prof-lista-grupo"
                                >
                                  <span className="prof-lista-materia-chip">
                                    {g.materia || '—'}
                                  </span>
                                  <div className="prof-lista-cursos">
                                    {g.cursos.map((c, idx2) => (
                                      <span
                                        key={`${p.id}-${g.materia}-${c}-${idx2}`}
                                        className="prof-lista-curso-chip"
                                      >
                                        {c}
                                      </span>
                                    ))}
                                  </div>
                                </div>
                              ))}

                              {grupos.length > 3 && (
                                <button
                                  type="button"
                                  onClick={() => toggleExpand(p.id)}
                                  className="prof-lista-vermas"
                                >
                                  {expanded ? 'Ver menos' : `Ver ${grupos.length - 3} más`}
                                </button>
                              )}

                              {/* Materias que cubre como suplente (no son suyas) */}
                              {(p.suplencias_materias || []).map((s, i) => (
                                <div
                                  key={`${p.id}-supl-${s.materia_id}-${s.anio}-${s.division}-${i}`}
                                  className="prof-lista-grupo"
                                >
                                  <span
                                    className="prof-lista-materia-chip prof-lista-materia-chip--suplencia"
                                    title={`Suplencia de ${s.titular_nombre || ''}${s.motivo ? ` (${s.motivo})` : ''}`}
                                  >
                                    {s.materia || '—'} (supl.)
                                  </span>
                                  <div className="prof-lista-cursos">
                                    <span className="prof-lista-curso-chip prof-lista-curso-chip--suplencia">
                                      {buildCursoLabel(s)}
                                    </span>
                                  </div>
                                </div>
                              ))}
                            </div>
                          )}

                        </div>
                      </div>
                    </td>

                    {/* Acciones */}
                    <td className="prof-lista-td prof-lista-td-right" colSpan={2}>
                      <div className="prof-lista-acciones">
                        <span
                          className={`prof-lista-badge ${
                            p.estado === 'Licencia'
                              ? 'prof-lista-badge--licencia'
                              : 'prof-lista-badge--activo'
                          }`}
                        >
                          {p.estado || 'Activo'}
                        </span>

                        <span
                          className={`prof-lista-badge ${
                            p.suplente
                              ? 'prof-lista-badge--suplente'
                              : 'prof-lista-badge--titular'
                          }`}
                        >
                          {p.suplente ? 'Suplente' : 'Titular'}
                        </span>

                        {/* Cadena de suplencias: de quién es suplente / quién lo cubre */}
                        {(p.suplente_de || []).map((nombre) => (
                          <span
                            key={`suple-${p.id}-${nombre}`}
                            className="prof-lista-badge prof-lista-badge--cubre"
                            title={`Cubre a ${nombre} como suplente`}
                          >
                            Suplente de {nombre}
                          </span>
                        ))}
                        {(p.cubierto_por || []).map((nombre) => (
                          <span
                            key={`cub-${p.id}-${nombre}`}
                            className="prof-lista-badge prof-lista-badge--cubierto"
                            title={`Cubierto por ${nombre}`}
                          >
                            Cubierto por {nombre}
                          </span>
                        ))}

                        {onVerFicha && (
                        <button
                          type="button"
                          onClick={() => onVerFicha(p)}
                          className="prof-lista-btn prof-lista-btn--ficha"
                          title="Ver ficha"
                        >
                          <Eye size={20} />
                        </button>
                        )}
                        {onMostrarDetalle && (
                        <button
                          type="button"
                          onClick={() => onMostrarDetalle(p)}
                          className="prof-lista-btn prof-lista-btn--editar"
                          title={soloLectura ? 'Dar de baja' : 'Editar'}
                        >
                          <Pencil size={20} />
                        </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}

              {/* Espaciador inferior */}
              {bottomSpacer > 0 && (
                <tr aria-hidden="true">
                  <td colSpan={3} style={{ height: `${bottomSpacer}px` }} />
                </tr>
              )}

              {/* Estado vacío */}
              {count === 0 && (
                <tr>
                  <td colSpan={3} className="prof-lista-empty">
                    No hay profesores para mostrar.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default ListaProfesores;
