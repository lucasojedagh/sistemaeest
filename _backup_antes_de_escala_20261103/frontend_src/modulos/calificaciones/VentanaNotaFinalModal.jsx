import React, { useEffect, useMemo, useState } from 'react';
import { X, Save, Lock } from 'lucide-react';
import { apiPost } from '../../compartido/lib/api';
import { useToast } from '../../compartido/componentes/Common/Toast';
import { useDeshacerAviso } from '../../compartido/lib/deshacer.jsx';
import useAviso from '../../compartido/componentes/modals/useAviso';

  // Modal para que administradores y jefe de preceptores configuren la ventana
// global durante la cual se pueden cargar las notas finales de cuatrimestre
// (la nota final del año es el promedio automático de esas dos).
export default function VentanaNotaFinalModal({ ventana, onClose, onGuardado }) {
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [, pedirConfirmacion, avisoModal] = useAviso();

  const parseFecha = (iso) => (iso ? iso.slice(0, 10) : '');
  const parseHora = (iso) => (iso ? iso.slice(11, 16) : '');

  const [fechaInicio, setFechaInicio] = useState(parseFecha(ventana?.fecha_inicio));
  const [horaInicio, setHoraInicio] = useState(parseHora(ventana?.fecha_inicio) || '08:00');
  const [fechaFin, setFechaFin] = useState(parseFecha(ventana?.fecha_fin));
  const [horaFin, setHoraFin] = useState(parseHora(ventana?.fecha_fin) || '23:59');
  const [alcance, setAlcance] = useState(ventana?.alcance === 'p1' || ventana?.alcance === 'p2' ? ventana.alcance : 'ambos');
  const [guardando, setGuardando] = useState(false);

  // Si se reabre con otra ventana, arrancar de esos valores (no de los viejos).
  useEffect(() => {
    setFechaInicio(parseFecha(ventana?.fecha_inicio));
    setHoraInicio(parseHora(ventana?.fecha_inicio) || '08:00');
    setFechaFin(parseFecha(ventana?.fecha_fin));
    setHoraFin(parseHora(ventana?.fecha_fin) || '23:59');
    setAlcance(ventana?.alcance === 'p1' || ventana?.alcance === 'p2' ? ventana.alcance : 'ambos');
  }, [ventana?.fecha_inicio, ventana?.fecha_fin, ventana?.alcance]);

  const aISO = (f, h) => (f && h ? `${f}T${h}` : null);
  const iniMS = useMemo(() => {
    const v = aISO(fechaInicio, horaInicio);
    const t = v ? new Date(v).getTime() : NaN;
    return Number.isNaN(t) ? null : t;
  }, [fechaInicio, horaInicio]);
  const finMS = useMemo(() => {
    const v = aISO(fechaFin, horaFin);
    const t = v ? new Date(v).getTime() : NaN;
    return Number.isNaN(t) ? null : t;
  }, [fechaFin, horaFin]);
  const rangoInvalido = iniMS != null && finMS != null && finMS <= iniMS;

  const fmtCorto = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  };

  // Atajos: hoy (hasta las 23:59) / 7 días / 30 días (desde ahora).
  const ponerPreset = (dias) => {
    const ahora = new Date();
    const fin = new Date(ahora.getTime() + dias * 24 * 60 * 60 * 1000);
    if (dias === 0) fin.setHours(23, 59, 0, 0);
    const f = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const h = (d) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    setFechaInicio(f(ahora));
    setHoraInicio(h(ahora));
    setFechaFin(f(fin));
    setHoraFin(h(fin));
  };

  const guardar = async () => {
    if (!fechaInicio || !horaInicio || !fechaFin || !horaFin) {
      showToast('Completá la fecha y hora de inicio y de fin', 'error');
      return;
    }
    if (rangoInvalido) {
      showToast('El fin tiene que ser posterior al inicio', 'error');
      return;
    }
    setGuardando(true);
    try {
      const res = await apiPost('/calificaciones/ventana', {
        fecha_inicio: fechaInicio,
        hora_inicio: horaInicio,
        fecha_fin: fechaFin,
        hora_fin: horaFin,
        alcance,
        ciclo_lectivo: new Date().getFullYear(),
      });
      onGuardado(res);
      // Deshacer: si ya existía una ventana, la restaura con sus valores previos.
      if (ventana?.fecha_inicio) {
        ofrecerDeshacer(
          {
            etiqueta: 'Ventana de nota final',
            deshacer: async () => {
              const v = ventana;
              const revert = await apiPost('/calificaciones/ventana', {
                fecha_inicio: String(v.fecha_inicio || '').slice(0, 10),
                hora_inicio: (v.fecha_inicio || '').slice(11, 16) || '08:00',
                fecha_fin: String(v.fecha_fin || '').slice(0, 10),
                hora_fin: (v.fecha_fin || '').slice(11, 16) || '23:59',
                alcance: v.alcance === 'p1' || v.alcance === 'p2' ? v.alcance : 'ambos',
                ciclo_lectivo: new Date().getFullYear(),
              });
              onGuardado(revert);
            },
          },
          'Ventana de nota final configurada'
        );
      } else {
        showToast('Ventana de nota final configurada', 'success');
      }
    } catch (e) {
      showToast(e?.message || 'No se pudo configurar la ventana', 'error');
    } finally {
      setGuardando(false);
    }
  };

  // Cierre inmediato: la ventana deja de estar abierta en el acto.
  // Solo se muestra cuando hay una ventana abierta (este modal ya es solo
  // para admin y jefe de preceptores).
  const [cerrando, setCerrando] = useState(false);
  const cerrarAhora = async () => {
    if (cerrando || guardando) return;
    const ok = await pedirConfirmacion({
      icon: 'warning',
      title: 'Cerrar la ventana ahora',
      text: 'Los profesores dejarán de poder cargar la nota final en este mismo momento. ¿Seguro?',
      confirmText: 'Sí, cerrar ahora',
      cancelText: 'Cancelar',
      danger: true,
    });
    if (!ok) return;
    setCerrando(true);
    try {
      const res = await apiPost('/calificaciones/ventana/cerrar', {});
      onGuardado(res);
      showToast('Ventana cerrada. Ya no se puede cargar la nota final.', 'success');
    } catch (e) {
      showToast(e?.message || 'No se pudo cerrar la ventana', 'error');
    } finally {
      setCerrando(false);
    }
  };

  return (
    <div className="fc-overlay" role="dialog" aria-modal="true">
      <div className="fc-modal ventana-modal">
        <div className="fc-header">
          <h2 className="fc-title">Ventana de nota final del año</h2>
          <button className="fc-icon-btn" onClick={onClose} aria-label="Cerrar">
            <X size={20} />
          </button>
        </div>
        <p className="ventana-desc">
          Durante este período se pueden cargar las notas finales según el alcance elegido.
        </p>
        <div className="ventana-field" style={{ marginBottom: 12 }}>
          <span>¿Qué nota se puede cargar?</span>
          <div role="radiogroup" aria-label="Alcance de la ventana" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 6 }}>
            <label className={`ventana-preset ${alcance === 'p1' ? 'ventana-preset-activo' : ''}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
              <input type="radio" name="alcance-ventana" value="p1" checked={alcance === 'p1'} onChange={() => setAlcance('p1')} />
              Solo 1er cuat.
            </label>
            <label className={`ventana-preset ${alcance === 'p2' ? 'ventana-preset-activo' : ''}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
              <input type="radio" name="alcance-ventana" value="p2" checked={alcance === 'p2'} onChange={() => setAlcance('p2')} />
              Solo 2do cuat.
            </label>
            <label className={`ventana-preset ${alcance === 'ambos' ? 'ventana-preset-activo' : ''}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
              <input type="radio" name="alcance-ventana" value="ambos" checked={alcance === 'ambos'} onChange={() => setAlcance('ambos')} />
              Los dos
            </label>
          </div>
        </div>
        {ventana?.fecha_inicio && (
          <p className={`ventana-estado ${ventana?.abierta ? 'ventana-estado-abierta' : 'ventana-estado-cerrada'}`}>
            {ventana?.abierta
              ? `Abierta ahora · cierra ${fmtCorto(ventana.fecha_fin)}`
              : `Cerrada · última ventana: ${fmtCorto(ventana.fecha_inicio)} → ${fmtCorto(ventana.fecha_fin)}`}
          </p>
        )}
        <div className="ventana-presets" role="group" aria-label="Períodos rápidos">
          <span className="ventana-presets-label">Rápido:</span>
          <button type="button" className="ventana-preset" onClick={() => ponerPreset(0)}>Solo hoy</button>
          <button type="button" className="ventana-preset" onClick={() => ponerPreset(7)}>7 días</button>
        </div>
        <div className="ventana-grid">
          <label className="ventana-field">
            <span>Fecha de inicio</span>
            <input type="date" value={fechaInicio} onChange={(e) => setFechaInicio(e.target.value)} />
          </label>
          <label className="ventana-field">
            <span>Hora de inicio</span>
            <input type="time" value={horaInicio} onChange={(e) => setHoraInicio(e.target.value)} />
          </label>
          <label className="ventana-field">
            <span>Fecha de fin</span>
            <input type="date" value={fechaFin} onChange={(e) => setFechaFin(e.target.value)} />
          </label>
          <label className="ventana-field">
            <span>Hora de fin</span>
            <input type="time" value={horaFin} onChange={(e) => setHoraFin(e.target.value)} />
          </label>
        </div>
        {rangoInvalido && (
          <p className="ventana-error" role="alert">El fin tiene que ser posterior al inicio.</p>
        )}
        <div className="ventana-actions">
          <button className="fc-btn-cancel" onClick={onClose} disabled={guardando || cerrando}>Cancelar</button>
          {ventana?.abierta && (
            <button
              className="fc-btn-danger"
              onClick={cerrarAhora}
              disabled={guardando || cerrando}
              title="Cierra la ventana en este momento"
            >
              <Lock size={16} /> {cerrando ? 'Cerrando…' : 'Cerrar ahora'}
            </button>
          )}
          <button
            className="fc-btn-save"
            onClick={guardar}
            disabled={guardando || cerrando || rangoInvalido}
            title={rangoInvalido ? 'El fin tiene que ser posterior al inicio' : 'Guardar ventana'}
          >
            <Save size={16} /> {guardando ? 'Guardando…' : 'Guardar ventana'}
          </button>
        </div>
      </div>
      {avisoModal}
    </div>
  );
}
