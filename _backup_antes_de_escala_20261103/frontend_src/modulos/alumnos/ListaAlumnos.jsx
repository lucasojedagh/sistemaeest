// ===============================
// FILE: src/components/Alumnos/ListaAlumnos.jsx
// ===============================
import React, { useRef, useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { BookOpen, Eye, CalendarClock, UserCircle, Pencil, UserPlus, SearchX } from 'lucide-react';
import { cursoLabel, parseCurso, especialidadLabel } from '../../compartido/lib/cursos';
import EmptyState from '../../compartido/componentes/Common/EmptyState';
import "./ListaAlumnos.css";

/*
 * Componente: ListaAlumnos
 * Props:
 *   alumnos          - Array de objetos de alumnos a mostrar
 *   onMostrarDetalle - Callback al hacer clic en "Ver detalle"
 *   onVerFicha       - Callback al hacer clic en "Ver ficha" (modal de solo lectura)
 *   onGuardarHorario - Callback al guardar cambios en el horario de un alumno
 *
 * Lista de alumnos paginada (50 por página desde Alumnos.jsx).
 * Muestra nombre, apellido, DNI, curso, grupo, estado y botones de accion.
 * La paginación se maneja en Alumnos.jsx; acá solo se renderiza el slice recibido.
 */

const ListaAlumnos = ({ alumnos = [], onMostrarDetalle, onVerFicha, onOpenCronograma, puedeEditarAlumno, cursoTexto, hayBusqueda, onLimpiarBusqueda, onAgregarAlumno }) => {
  const containerRef = useRef(null);

  const total = Array.isArray(alumnos) ? alumnos.length : 0;
  const visibleAlumnos = alumnos;

  // Cierra popovers con Escape
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setDniFocusId(null);
        setCourseFocusId(null);
      }
    };
    el.addEventListener('keydown', onKey);
    return () => el.removeEventListener('keydown', onKey);
  }, []);

  // Al cambiar de página (cambia el slice), vuelve arriba
  useEffect(() => {
    if (containerRef.current) containerRef.current.scrollTop = 0;
  }, [alumnos]);

  // -------- UI popovers y helpers --------
  const [dniFocusId, setDniFocusId] = useState(null);
  const [dniPlacement, setDniPlacement] = useState('bottom');
  const [courseFocusId, setCourseFocusId] = useState(null);
  const [coursePlacement, setCoursePlacement] = useState('bottom');

  const hasItems = (val) =>
    Array.isArray(val) ? val.length > 0 : (typeof val === 'string' ? val.trim().length > 0 : false);

  const formatearCurso = (s, fallback) => {
    const base = s || fallback || 'Sin curso';
    return cursoLabel(base);
  };

  const formatearDNI = (val) => {
    if (!val && val !== 0) return '—';
    const digits = String(val).replace(/\D/g, '');
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  };

  const lastCursoEgreso = (al) => {
    if (!al) return '';
    if (al.anio) return al.anio;
    if (al.egreso_anio) {
      return cursoLabel({ anio: al.egreso_anio, division: al.egreso_division || null });
    }
    return '';
  };

  const Avatar = ({ alumno, chipBg, iconColor, isEgresado }) => {
    const n = String(alumno.nombre || '').trim();
    const a = String(alumno.apellido || '').trim();
    const iniciales = `${n[0] || ''}${a[0] || ''}`.toUpperCase();
    return (
      <div className={`lista-alumnos-avatar-wrap ${isEgresado ? 'lista-alumnos-avatar-wrap-egresado' : ''}`}>
        <div className={`lista-alumnos-avatar-inner ${chipBg}`}>
          <BookOpen size={20} className={iconColor} />
        </div>
        <span className="lista-alumnos-avatar-initials">
          {iniciales || '—'}
        </span>
        {isEgresado && (
          <span className="lista-alumnos-avatar-dot" title="Egresado" aria-hidden="true" />
        )}
      </div>
    );
  };

  const toggleDni = (e, id, forceTop = false) => {
    e.stopPropagation();
    if (dniFocusId !== id) {
      if (forceTop) setDniPlacement('top');
      else {
        const rect = e.currentTarget.getBoundingClientRect();
        const EST_ALT = 56, MARGEN = 8;
        const espacioAbajo = window.innerHeight - rect.bottom;
        const espacioArriba = rect.top;
        setDniPlacement(espacioAbajo < EST_ALT + MARGEN && espacioArriba > espacioAbajo ? 'top' : 'bottom');
      }
      setDniFocusId(id);
    } else setDniFocusId(null);
  };
  const onDniKeyDown = (e, id, forceTop = false) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleDni(e, id, forceTop); }
  };

  const toggleCourse = (e, id, forceTop = false) => {
    e.stopPropagation();
    if (courseFocusId !== id) {
      if (forceTop) setCoursePlacement('top');
      else {
        const rect = e.currentTarget.getBoundingClientRect();
        const EST_ALT = 56, MARGEN = 8;
        const espacioAbajo = window.innerHeight - rect.bottom;
        const espacioArriba = rect.top;
        setCoursePlacement(espacioAbajo < EST_ALT + MARGEN && espacioArriba > espacioAbajo ? 'top' : 'bottom');
      }
      setCourseFocusId(id);
    } else setCourseFocusId(null);
  };
  const onCourseKeyDown = (e, id, forceTop = false) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleCourse(e, id, forceTop); }
  };

  return (
    <div className="lista-alumnos">
      <div className="lista-alumnos-fade-top" />
      <div className="lista-alumnos-fade-bottom" />

      <div
        ref={containerRef}
        tabIndex={0}
        className="lista-alumnos-scroll"
        style={{ scrollPaddingTop: '0.5rem', scrollPaddingBottom: '0.5rem' }}
        aria-label="Lista de alumnos (usa flechas para desplazarte)"
      >
        <div className="lista-alumnos-card">
          <table className="lista-alumnos-table">
            <tbody className="lista-alumnos-tbody">
              {visibleAlumnos.map((alumno, idx) => {
                const isLast = idx === visibleAlumnos.length - 1;
                const esEgresado =
                  String(alumno?.estado || '').toLowerCase() === 'egresado' || Boolean(alumno?.egreso_year);

                const estadoChips = (
                  <div className="lista-alumnos-estado-chips">
                    {String(alumno.estado).toLowerCase() === 'egresado' && (
                      <span className="lista-alumnos-chip lista-alumnos-chip-egresado">
                        Egresado
                        {alumno.egreso_anio ? ` ${cursoLabel({ anio: alumno.egreso_anio, division: alumno.egreso_division || null })}` : ''}
                        {alumno.egreso_year ? ` · ${alumno.egreso_year}` : ''}
                      </span>
                    )}
                    {!!alumno?.tiene_ppi && (
                      <span className="lista-alumnos-chip lista-alumnos-chip-ppi" title="Proyecto Pedagógico de Inclusión">
                        P. P. I.
                      </span>
                    )}
                    {!!alumno?.tiene_ae && (
                      <span className="lista-alumnos-chip lista-alumnos-chip-ae" title="Acompañante Externo">
                        A. E.
                      </span>
                    )}
                    {hasItems(alumno.recursando) && (
                      <span className="lista-alumnos-chip lista-alumnos-chip-rec">
                        Recursando
                      </span>
                    )}
                    {hasItems(alumno.intensificacion) && (
                      <span className="lista-alumnos-chip lista-alumnos-chip-int">
                        Intensificación
                      </span>
                    )}
                  </div>
                );

                const genero = String(alumno.genero || '').toUpperCase();
                const isF = genero === 'F';
                const isM = genero === 'M';
                const chipBg   = isF ? 'lista-alumnos-avatar-f' : isM ? 'lista-alumnos-avatar-m' : 'lista-alumnos-avatar-o';
                const iconColor = isF ? 'lista-alumnos-icon-f' : isM ? 'lista-alumnos-icon-m' : 'lista-alumnos-icon-o';

                const apTokens = String(alumno.apellido || '').trim().split(/\s+/).filter(Boolean);
                const apLabel = apTokens.length > 1 ? 'Apellidos' : 'Apellido';

                const dniFormateado = formatearDNI(alumno.dni);
                const cursoMostrar = (() => {
                  const base = alumno.anio || lastCursoEgreso(alumno) || '';
                  const div = alumno.curso?.division;
                  if (div && !base.includes(div)) {
                    const parsed = parseCurso(base);
                    if (parsed.anio) {
                      return cursoLabel({ anio: parsed.anio, division: div });
                    }
                  }
                  return formatearCurso(base);
                })();
                const grupo = alumno.grupo;
                // Colores segun grupo: A = verde, B = ambar
                const grupoColors = grupo === 'A' ? 'lista-alumnos-grupo-a' : 'lista-alumnos-grupo-b';

                return (
                  <tr
                    key={alumno.id}
                    className="lista-alumnos-row"
                  >
                    <td className="lista-alumnos-cell-main">
                      <div className="lista-alumnos-avatar-row">
                        <Avatar alumno={alumno} chipBg={chipBg} iconColor={iconColor} isEgresado={esEgresado} />
                        <div className="lista-alumnos-info">
                          <div className="lista-alumnos-name-row">
                            <div className="lista-alumnos-name-label">
                              {apLabel}:{' '}
                              <span className="lista-alumnos-name-value">
                                {alumno.apellido || '—'}
                              </span>
                            </div>
                            <div className="lista-alumnos-name-label">
                              Nombre:{' '}
                              <span className="lista-alumnos-name-value">
                                {alumno.nombre || '—'}
                              </span>
                            </div>
                          </div>

                          <div className="lista-alumnos-badges">
                            {/* DNI */}
                            <div className="lista-alumnos-dni-wrap" onClick={(e) => e.stopPropagation()}>
                              <div
                                role="button" tabIndex={0}
                                onClick={(e) => toggleDni(e, alumno.id, isLast)}
                                onKeyDown={(e) => onDniKeyDown(e, alumno.id, isLast)}
                                title="Ver DNI en grande" aria-expanded={dniFocusId === alumno.id}
                                className="lista-alumnos-dni-trigger"
                              >
                                <span className="lista-alumnos-dni-chip">DNI</span>
                                <span className="lista-alumnos-dni-value">
                                  {dniFormateado}
                                </span>
                              </div>
                              <AnimatePresence>
                                {dniFocusId === alumno.id && (
                                  <motion.div
                                    initial={{ scale: 0.95, y: dniPlacement === 'top' ? -6 : 6 }}
                                    animate={{ scale: 1, y: 0 }}
                                    exit={{ opacity: 0, scale: 0.98, y: dniPlacement === 'top' ? -6 : 6 }}
                                    transition={{ duration: 0.18, ease: 'easeOut' }}
                                    className={`lista-alumnos-popover ${dniPlacement === 'top' ? 'lista-alumnos-popover-top' : 'lista-alumnos-popover-bottom'}`}
                                  >
                                    <div className="lista-alumnos-popover-value">{dniFormateado}</div>
                                  </motion.div>
                                )}
                              </AnimatePresence>
                            </div>

                            {/* Curso */}
                            <div className="lista-alumnos-curso-wrap" onClick={(e) => e.stopPropagation()}>
                              <div
                                role="button" tabIndex={0}
                                onClick={(e) => toggleCourse(e, alumno.id, isLast)}
                                onKeyDown={(e) => onCourseKeyDown(e, alumno.id, isLast)}
                                title="Ver curso en grande" aria-expanded={courseFocusId === alumno.id}
                                className={`lista-alumnos-curso-trigger ${esEgresado ? 'lista-alumnos-curso-trigger-egresado' : 'lista-alumnos-curso-trigger-activo'}`}
                              >
                                <span className={`lista-alumnos-curso-chip ${esEgresado ? 'lista-alumnos-curso-chip-egresado' : 'lista-alumnos-curso-chip-activo'}`}>
                                  {esEgresado ? 'Últ. curso' : 'Curso'}
                                </span>
                                <span className={`lista-alumnos-curso-value ${esEgresado ? 'lista-alumnos-curso-value-egresado' : 'lista-alumnos-curso-value-activo'}`}>
                                  {formatearCurso(cursoMostrar)}
                                </span>
                              </div>
                              <AnimatePresence>
                                {courseFocusId === alumno.id && (
                                  <motion.div
                                    initial={{ scale: 0.95, y: coursePlacement === 'top' ? -6 : 6 }}
                                    animate={{ scale: 1, y: 0 }}
                                    exit={{ opacity: 0, scale: 0.98, y: coursePlacement === 'top' ? -6 : 6 }}
                                    transition={{ duration: 0.18, ease: 'easeOut' }}
                                    className={`lista-alumnos-popover ${coursePlacement === 'top' ? 'lista-alumnos-popover-top' : 'lista-alumnos-popover-bottom'}`}
                                  >
                                    <div className="lista-alumnos-popover-value">{formatearCurso(cursoMostrar)}</div>
                                  </motion.div>
                                )}
                              </AnimatePresence>
                            </div>

                            {/* Badge de grupo (A/B): se muestra solo si el alumno tiene grupo asignado */}
                            {grupo && (
                              <div className={`lista-alumnos-grupo ${grupoColors}`}>
                                <span className="lista-alumnos-grupo-label">Grupo</span>
                                <span className="lista-alumnos-grupo-value">{grupo}</span>
                              </div>
                            )}

                            {/* Badge de especialidad: solo para 4to año en adelante */}
                            {alumno.especialidad && (
                              <div className="lista-alumnos-esp" title={especialidadLabel(alumno.especialidad)}>
                                <span className="lista-alumnos-esp-label">Esp.</span>
                                <span className="lista-alumnos-esp-value">{especialidadLabel(alumno.especialidad)}</span>
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                    </td>

                    <td className="lista-alumnos-cell-actions" colSpan={2}>
                      <div className="lista-alumnos-actions">
                        {estadoChips}
                        {onVerFicha && (
                          <button
                            onClick={() => onVerFicha(alumno)}
                            className="lista-alumnos-btn-ficha"
                            title="Ver ficha"
                            aria-label={`Ver ficha de ${alumno?.nombre || 'alumno'}`}
                          >
                            <UserCircle size={20} />
                          </button>
                        )}
                        {(() => {
                          const puedeEditar = !puedeEditarAlumno || puedeEditarAlumno(alumno);
                          return (
                            <button
                              onClick={() => !esEgresado && puedeEditar && onOpenCronograma(alumno)}
                              className={`lista-alumnos-btn-horario ${esEgresado ? 'lista-alumnos-btn-horario-egresado' : 'lista-alumnos-btn-horario-activo'}`}
                              title={esEgresado ? 'Alumno egresado (no tiene horario activo)' : (puedeEditar ? 'Ver cronograma' : 'Solo podés editar alumnos de tus cursos asignados')}
                              aria-label={`Ver cronograma de ${alumno?.nombre || 'alumno'}`}
                              disabled={esEgresado || !puedeEditar}
                            >
                              <CalendarClock size={20} />
                            </button>
                          );
                        })()}
                        {(!puedeEditarAlumno || puedeEditarAlumno(alumno)) && (
                        <button
                          onClick={() => onMostrarDetalle(alumno)}
                          className="lista-alumnos-btn-editar"
                          title="Editar ficha"
                          aria-label={`Editar ficha de ${alumno?.nombre || 'alumno'}`}
                        >
                          <Pencil size={20} />
                        </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}

              {/* Estado vacío que enseña: explica por qué no hay nada y qué hacer */}
              {total === 0 && (
                <tr>
                  <td colSpan={3} className="lista-alumnos-empty">
                    <EmptyState
                      icon={hayBusqueda ? SearchX : UserPlus}
                      title={hayBusqueda ? "No encontramos a nadie con esa búsqueda" : (cursoTexto ? `Todavía no hay alumnos en ${cursoTexto}` : "Todavía no hay alumnos acá")}
                      description={hayBusqueda
                        ? "Probá con solo el apellido, o con el DNI con o sin puntos."
                        : "Cargá el primero con el botón de abajo."}
                      action={(
                        <div className="lista-alumnos-empty-actions">
                          {hayBusqueda && onLimpiarBusqueda && (
                            <button type="button" className="alumnos-btn-add" onClick={onLimpiarBusqueda}>
                              Limpiar búsqueda
                            </button>
                          )}
                          {onAgregarAlumno && (
                            <button type="button" className="alumnos-btn-add" onClick={onAgregarAlumno}>
                              <UserPlus size={16} /> Agregar alumno
                            </button>
                          )}
                        </div>
                      )}
                      compact
                    />
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

export default ListaAlumnos;
