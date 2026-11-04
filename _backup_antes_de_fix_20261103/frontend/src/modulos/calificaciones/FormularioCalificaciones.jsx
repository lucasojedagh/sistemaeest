import React, { useState, useEffect, useCallback } from 'react';
import { X, Plus, Trash2, Printer, Save, Download } from 'lucide-react';
import { apiGet, apiPut } from '../../compartido/lib/api';
import { exportCSV } from '../../compartido/utils/export';
import { mensajeDescarga } from '../../compartido/lib/descarga';
import { useToast } from '../../compartido/componentes/Common/Toast';
import { useDeshacerAviso } from '../../compartido/lib/deshacer.jsx';
import PlanillaModal from './PlanillaModal';
import './FormularioCalificaciones.css';

function normalizeNotes(arr) {
  return (arr || [])
    .map((v) => {
      if (v === '' || v === null || v === undefined) return NaN;
      const s = String(v).trim().replace(',', '.');
      if (s === '') return NaN;
      return parseFloat(s);
    })
    .filter((v) => !isNaN(v) && v > 0);
}

function notesEqual(a, b) {
  const na = normalizeNotes(a);
  const nb = normalizeNotes(b);
  if (na.length !== nb.length) return false;
  return na.every((v, i) => Math.abs(v - nb[i]) < 1e-6);
}

const PRELIMINAR_OPCIONES = ["TEA", "TEP", "TED"];

function normalizePreliminar(arr) {
  return (arr || [])
    .map((v) => String(v ?? "").trim().toUpperCase())
    .filter((v) => PRELIMINAR_OPCIONES.includes(v));
}

function prelimEqual(a, b) {
  const na = normalizePreliminar(a);
  const nb = normalizePreliminar(b);
  if (na.length !== nb.length) return false;
  return na.every((v, i) => v === nb[i]);
}

function normalizeFinalCuatri(v) {
  if (v === '' || v === null || v === undefined) return null;
  const s = String(v).trim().replace(',', '.');
  if (s === '') return null;
  const f = parseFloat(s);
  if (Number.isNaN(f)) return null;
  return f;
}

function finalCuatriEqual(a, b) {
  const na = normalizeFinalCuatri(Array.isArray(a) ? a[0] : a);
  const nb = normalizeFinalCuatri(Array.isArray(b) ? b[0] : b);
  if (na === null && nb === null) return true;
  if (na === null || nb === null) return false;
  return Math.abs(na - nb) < 1e-6;
}

function promedioFinalAnio(p1f, p2f) {
  const a = normalizeFinalCuatri(Array.isArray(p1f) ? p1f[0] : p1f);
  const b = normalizeFinalCuatri(Array.isArray(p2f) ? p2f[0] : p2f);
  if (a === null || b === null) return '';
  const prom = Math.round(((a + b) / 2) * 100) / 100;
  return String(prom).replace(/\.0+$/, '');
}

export default function FormularioCalificaciones({
  anio,
  division,
  materiaId,
  materiaNombre,
  ciclo,
  finalHabilitado = true,
  p1Habilitado,
  p2Habilitado,
  // Cambia este numero para forzar a volver a pedir las notas (boton
  // "Hay novedades"): se suma 1 cada vez que hay que recargar.
  recargarKey = 0,
  onClose,
  onSaved,
}) {
  // Alcance por período: si no llegan las props nuevas, se usa la vieja para ambos.
  const p1FinalHabilitado = p1Habilitado ?? finalHabilitado;
  const p2FinalHabilitado = p2Habilitado ?? finalHabilitado;
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [students, setStudents] = useState([]);
  const [original, setOriginal] = useState([]);
  const [saving, setSaving] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [preview, setPreview] = useState(false);
  const [profesor, setProfesor] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const q = new URLSearchParams({
        anio,
        division,
        materia_id: materiaId,
        ciclo,
      }).toString();
      const res = await apiGet(`/calificaciones/por-curso-materia?${q}`);
      const payload = res?.data ?? res;
      const alumnos = payload?.alumnos ?? [];
      setProfesor(payload?.profesor ?? null);
        const mapped = alumnos.map((a) => {
        const cal = a.calificaciones ?? {};
        const p1 = (cal.p1 ?? []).map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        const p2 = (cal.p2 ?? []).map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        const p1p = (cal.p1_preliminar ?? []).map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        const p2p = (cal.p2_preliminar ?? []).map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        const fin = (cal.final_anio ?? []).map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        const p1fRaw = cal.p1_final_cuatri ?? cal.p1_final_cuatrimestre ?? [];
        const p2fRaw = cal.p2_final_cuatri ?? cal.p2_final_cuatrimestre ?? [];
        const p1f = p1fRaw.map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        const p2f = p2fRaw.map((n) => String(n.valor ?? '').replace(/\.0+$/, ''));
        return {
          id: a.alumno_id,
          nombre: a.nombre,
          apellido: a.apellido,
          p1: p1.length ? p1 : [''],
          p2: p2.length ? p2 : [''],
          p1p: p1p.length ? p1p : [''],
          p2p: p2p.length ? p2p : [''],
          p1f: p1f.length ? p1f : [''],
          p2f: p2f.length ? p2f : [''],
          final: fin.length ? fin : [''],
        };
      });
      setStudents(mapped);
      setOriginal(mapped.map((s) => ({ p1: s.p1, p2: s.p2, p1p: s.p1p, p2p: s.p2p, p1f: s.p1f, p2f: s.p2f, final: s.final })));
    } catch (e) {
      console.error(e);
      setLoadError(true);
    } finally {
      setLoading(false);
    }
  }, [anio, division, materiaId, ciclo]);

  useEffect(() => {
    load();
  }, [load, recargarKey]);

  const keyFor = (period, kind) => {
    if (kind === 'final') return 'final';
    if (kind === 'final_cuatri') return period === 1 ? 'p1f' : 'p2f';
    if (kind === 'preliminar') return period === 1 ? 'p1p' : 'p2p';
    return period === 1 ? 'p1' : 'p2';
  };

  const setNota = (idx, period, noteIdx, value, kind = 'regular') => {
    setStudents((prev) =>
      prev.map((s, i) => {
        if (i !== idx) return s;
        const key = keyFor(period, kind);
        const next = [...s[key]];
        next[noteIdx] = value;
        return { ...s, [key]: next };
      })
    );
  };

  const addNota = (idx, period, kind = 'regular') => {
    setStudents((prev) =>
      prev.map((s, i) => {
        if (i !== idx) return s;
        const key = keyFor(period, kind);
        return { ...s, [key]: [...s[key], ''] };
      })
    );
  };

  const removeNota = (idx, period, noteIdx, kind = 'regular') => {
    setStudents((prev) =>
      prev.map((s, i) => {
        if (i !== idx) return s;
        const key = keyFor(period, kind);
        if (s[key].length <= 1) return s;
        const next = s[key].filter((_, j) => j !== noteIdx);
        return { ...s, [key]: next };
      })
    );
  };

  const hasChanges = students.some((s, i) => {
    const o = original[i] || { p1: [], p2: [], p1p: [], p2p: [], p1f: [], p2f: [], final: [] };
    return (
      !notesEqual(s.p1, o.p1) ||
      !notesEqual(s.p2, o.p2) ||
      !prelimEqual(s.p1p, o.p1p) ||
      !prelimEqual(s.p2p, o.p2p) ||
      !finalCuatriEqual(s.p1f, o.p1f) ||
      !finalCuatriEqual(s.p2f, o.p2f)
    );
  });

  const changedStudents = students
    .map((s, i) => ({ s, o: original[i] || { p1: [], p2: [], p1p: [], p2p: [], p1f: [], p2f: [], final: [] } }))
    .filter(({ s, o }) => !notesEqual(s.p1, o.p1) || !notesEqual(s.p2, o.p2) || !prelimEqual(s.p1p, o.p1p) || !prelimEqual(s.p2p, o.p2p) || !finalCuatriEqual(s.p1f, o.p1f) || !finalCuatriEqual(s.p2f, o.p2f));

  const doSave = useCallback(async () => {
    if (!changedStudents.length) {
      showToast('No hay cambios para guardar', 'info');
      return true;
    }
    // La nota final de cuatrimestre solo se puede cargar si la ventana habilita ese período.
    const tocoP1 = changedStudents.some(({ s, o }) => !finalCuatriEqual(s.p1f, o.p1f));
    const tocoP2 = changedStudents.some(({ s, o }) => !finalCuatriEqual(s.p2f, o.p2f));
    if (tocoP1 && !p1FinalHabilitado) {
      showToast('La nota final del 1er cuatrimestre no está habilitada por la ventana actual.', 'error');
      return false;
    }
    if (tocoP2 && !p2FinalHabilitado) {
      showToast('La nota final del 2do cuatrimestre no está habilitada por la ventana actual.', 'error');
      return false;
    }
    for (const { s } of changedStudents) {
      for (const v of normalizeNotes([...s.p1, ...s.p2])) {
        if (v < 1 || v > 10) {
          showToast(`La nota de ${s.apellido}, ${s.nombre} debe estar entre 1 y 10`, 'error');
          return false;
        }
      }
      for (const v of [...normalizePreliminar(s.p1p), ...normalizePreliminar(s.p2p)]) {
        if (!PRELIMINAR_OPCIONES.includes(v)) {
          showToast(`La valoración preliminar de ${s.apellido}, ${s.nombre} debe ser TEA, TEP o TED`, 'error');
          return false;
        }
      }
      for (const v of [normalizeFinalCuatri(s.p1f?.[0]), normalizeFinalCuatri(s.p2f?.[0])].filter((v) => v !== null)) {
        if (v < 1 || v > 10) {
          showToast(`La nota final de cuatrimestre de ${s.apellido}, ${s.nombre} debe estar entre 1 y 10`, 'error');
          return false;
        }
      }
    }
    setSaving(true);
    try {
      const detalle = motivo?.trim() || 'Carga de calificaciones (formulario)';
      // Una sola peticion para todos los alumnos -> una sola tarjeta en el historial.
      const alumnosPayload = [];
      const armarEntradas = (s) => {
        const entradas = [];
        const mkEntrada = (periodo, vals, tipo = 'regular') => ({
          anio,
          division,
          ciclo_lectivo: ciclo,
          periodo,
          tipo_calificacion: tipo,
          materia_id: materiaId,
          materia_nombre: materiaNombre,
          detalle,
          notas: vals.map((v) => ({ valor: v })),
        });
        const p1 = normalizeNotes(s.p1);
        if (p1.length) entradas.push(mkEntrada(1, p1));
        const p2 = normalizeNotes(s.p2);
        if (p2.length) entradas.push(mkEntrada(2, p2));
        const p1p = normalizePreliminar(s.p1p);
        if (p1p.length) entradas.push(mkEntrada(1, p1p, 'preliminar'));
        const p2p = normalizePreliminar(s.p2p);
        if (p2p.length) entradas.push(mkEntrada(2, p2p, 'preliminar'));
        // Finales solo se envían para los períodos habilitados. Los no habilitados
        // no se tocan (el backend los preserva), así editar parciales no da 403.
        const algunaHabilitada = p1FinalHabilitado || p2FinalHabilitado;
        if (algunaHabilitada) {
          if (p1FinalHabilitado) {
            const p1f = normalizeFinalCuatri(s.p1f?.[0]);
            if (p1f !== null) entradas.push(mkEntrada(1, [p1f], 'final_cuatrimestre'));
          }
          if (p2FinalHabilitado) {
            const p2f = normalizeFinalCuatri(s.p2f?.[0]);
            if (p2f !== null) entradas.push(mkEntrada(2, [p2f], 'final_cuatrimestre'));
          }
          // Nota final del año: automática = (final1 + final2) / 2.
          // Se manda si hay ventana abierta (aunque sea un solo período), porque
          // el promedio se recalcula con el valor preservado del otro período.
          const autoFinal = promedioFinalAnio(s.p1f, s.p2f);
          if (autoFinal !== '') entradas.push(mkEntrada(0, [parseFloat(autoFinal)], 'final_anio'));
        }
        return entradas;
      };
      for (const { s } of changedStudents) {
        const entradas = armarEntradas(s);
        if (entradas.length) alumnosPayload.push({ alumno_id: s.id, entradas });
      }

      if (!alumnosPayload.length) {
        showToast('No hay cambios para guardar', 'info');
        return true;
      }

      const res = await apiPut('/calificaciones/batch', { alumnos: alumnosPayload });
      const r = res?.data ?? res ?? {};
      const fallidos = Array.isArray(r.fallidos) ? r.fallidos : [];

      if (fallidos.length > 0) {
        showToast(
          `Se guardaron ${r.guardados ?? '?'} alumno(s), ${fallidos.length} fallaron. Revisa e intenta de nuevo.`,
          'error'
        );
        return false;
      }
      const snap = students.map((s) => {
        const auto = promedioFinalAnio(s.p1f, s.p2f);
        return { p1: s.p1, p2: s.p2, p1p: s.p1p, p2p: s.p2p, p1f: s.p1f, p2f: s.p2f, final: auto !== '' ? [auto] : [''] };
      });
      const originalAntes = original;
      // Deshacer: revierte a las notas anteriores de los alumnos que cambiaron.
      ofrecerDeshacer(
        {
          etiqueta: 'Calificaciones guardadas',
          deshacer: async () => {
            const revert = [];
            for (let i = 0; i < students.length; i++) {
              const cur = students[i];
              const o = originalAntes[i] || { p1: [], p2: [], p1p: [], p2p: [], p1f: [], p2f: [], final: [] };
              if (
                notesEqual(cur.p1, o.p1) &&
                notesEqual(cur.p2, o.p2) &&
                prelimEqual(cur.p1p, o.p1p) &&
                prelimEqual(cur.p2p, o.p2p) &&
                finalCuatriEqual(cur.p1f, o.p1f) &&
                finalCuatriEqual(cur.p2f, o.p2f)
              ) continue;
              const entradas = armarEntradas({ ...cur, ...o });
              if (entradas.length) revert.push({ alumno_id: cur.id, entradas });
            }
            if (revert.length) await apiPut('/calificaciones/batch', { alumnos: revert });
            setStudents(originalAntes.map((o, i) => ({ ...(students[i] || {}), ...o })));
            setOriginal(originalAntes);
            onSaved?.();
          },
        },
        'Calificaciones guardadas correctamente'
      );
      setOriginal(snap);
      onSaved?.();
      return true;
    } catch (e) {
      console.error(e);
      showToast('No se pudieron guardar las calificaciones', 'error');
      return false;
    } finally {
      setSaving(false);
    }
  }, [changedStudents, students, motivo, anio, division, ciclo, materiaId, materiaNombre, onSaved, showToast, ofrecerDeshacer, original, p1FinalHabilitado, p2FinalHabilitado]);

  const handlePreview = async () => {
    if (hasChanges) {
      const ok = await doSave();
      if (!ok) return;
    }
    setPreview(true);
  };

  const renderPeriod = (i, s, period, kind, headLabel) => {
    // Preliminar: único select con TEA / TEP / TED
    if (kind === 'preliminar') {
      const val = String(s[keyFor(period, kind)]?.[0] ?? '').trim().toUpperCase();
      const safeVal = PRELIMINAR_OPCIONES.includes(val) ? val : '';
      return (
        <div className="fc-period">
          <div className="fc-period-head">
            <span>{headLabel}</span>
          </div>
          <div className="fc-note-row">
            <select
              className="fc-note-input"
              value={safeVal}
              onChange={(e) => setNota(i, period, 0, e.target.value, kind)}
            >
              <option value="">— Seleccionar —</option>
              <option value="TEA">TEA</option>
              <option value="TEP">TEP</option>
              <option value="TED">TED</option>
            </select>
          </div>
        </div>
      );
    }
    const arr = s[keyFor(period, kind)];
    return (
      <div className="fc-period">
        <div className="fc-period-head">
          <span>{headLabel}</span>
        </div>
        {arr.map((n, j) => (
          <div className="fc-note-row" key={j}>
            <input
              className="fc-note-input"
              type="number"
              min="1"
              max="10"
              step="0.1"
              value={n}
              placeholder="Nota"
              onChange={(e) => setNota(i, period, j, e.target.value, kind)}
            />
            <button
              className="fc-icon-btn-sm"
              onClick={() => removeNota(i, period, j, kind)}
              disabled={arr.length <= 1}
              aria-label="Quitar nota"
            >
              <Trash2 size={14} />
            </button>
          </div>
        ))}
        <button className="fc-add-btn" onClick={() => addNota(i, period, kind)}>
          <Plus size={14} /> Agregar nota
        </button>
      </div>
    );
  };

  const renderFinalCuatri = (i, s, period) => {
    const key = period === 1 ? 'p1f' : 'p2f';
    const label = period === 1 ? 'NOTA FINAL 1° CUATRIMESTRE' : 'NOTA FINAL 2° CUATRIMESTRE';
    const habilitado = period === 1 ? p1FinalHabilitado : p2FinalHabilitado;
    return (
      <div className="fc-period">
        <div className="fc-period-head">
          <span>{label}</span>
        </div>
        <div className="fc-note-row">
          <input
            className="fc-note-input"
            type="number"
            min="1"
            max="10"
            step="0.1"
            value={s[key]?.[0] ?? ''}
            placeholder="Nota final"
            disabled={!habilitado}
            onChange={(e) => setNota(i, period, 0, e.target.value, 'final_cuatri')}
            title={habilitado ? label : 'Este período no está habilitado por la ventana actual'}
          />
        </div>
        {!habilitado && (
          <p className="fc-final-hint">
            Este período no está habilitado por la ventana actual.
          </p>
        )}
      </div>
    );
  };

  const renderFinal = (i, s) => {
    const auto = promedioFinalAnio(s.p1f, s.p2f);
    const sinFinales = auto === '';
    return (
      <div className="fc-period fc-period-final">
        <div className="fc-period-head">
          <span>NOTA FINAL DEL AÑO</span>
        </div>
        <div className="fc-note-row">
          <input
            className="fc-note-input fc-note-input-final"
            type="number"
            min="1"
            max="10"
            step="0.1"
            value={auto}
            placeholder="Todavía no hay notas cargadas"
            disabled
            readOnly
            title="Nota final del año"
          />
        </div>
        {sinFinales && (
          <p className="fc-final-hint">
            Todavía no hay notas cargadas.
          </p>
        )}
      </div>
    );
  };

  return (
    <div className="fc-overlay" role="dialog" aria-modal="true">
      <div className="fc-modal">
        <div className="fc-header">
          <div className="fc-header-titles">
            <h2 className="fc-title">Cargar calificaciones</h2>
            <div className="fc-params">
              <span className="fc-param"><b>Asignatura:</b> {materiaNombre}</span>
              <span className="fc-param"><b>Profesor:</b> {profesor || '—'}</span>
              <span className="fc-param"><b>Curso:</b> {anio}°</span>
              <span className="fc-param"><b>División:</b> {division}</span>
              <span className="fc-param"><b>Ciclo:</b> {ciclo}</span>
            </div>
          </div>
          <button className="fc-icon-btn" onClick={onClose} aria-label="Cerrar">
            <X size={20} />
          </button>
        </div>

        <div className="fc-toolbar">
          <input
            className="fc-motivo"
            type="text"
              placeholder="Motivo (opcional, historial)"
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
          />
          <div className="fc-toolbar-actions">
            
            <button className="fc-btn fc-btn-ghost" onClick={handlePreview} disabled={saving}>
              <Printer size={16} /> Vista previa de la planilla
            </button>
            <button className="fc-btn fc-btn-primary" onClick={doSave} disabled={saving || !hasChanges}>
              <Save size={16} /> {saving ? 'Guardando…' : 'Guardar'}
            </button>
          </div>
        </div>

        <div className="fc-body">
          {loading && <div className="fc-state">Cargando alumnos…</div>}
          {loadError && (
            <div className="fc-state fc-state-error">
              No se pudieron cargar las calificaciones.{' '}
              <button className="fc-link" onClick={load}>
                Reintentar
              </button>
            </div>
          )}
          {!loading && !loadError && students.length === 0 && (
            <div className="fc-state">
              Todavía no hay alumnos en este curso. Inscribí el primero en Alumnos y volvé acá a cargar notas.
            </div>
          )}
          {!loading &&
            !loadError &&
            students.map((s, i) => {
              return (
                <div className="fc-student" key={s.id}>
                  <div className="fc-student-name">
                    {s.apellido}, {s.nombre}
                  </div>
                  <div className="fc-periods">
                    {renderPeriod(i, s, 1, 'regular', 'CALIFICACIONES VALORACIONES PARCIALES 1° CUATRIMESTRE')}
                    {renderPeriod(i, s, 1, 'preliminar', '1° VALORACIÓN PRELIMINAR')}
                    {renderFinalCuatri(i, s, 1)}
                    {renderPeriod(i, s, 2, 'regular', 'CALIFICACIONES VALORACIONES PARCIALES 2° CUATRIMESTRE')}
                    {renderPeriod(i, s, 2, 'preliminar', '2° VALORACIÓN PRELIMINAR')}
                    {renderFinalCuatri(i, s, 2)}
                  </div>
                  {renderFinal(i, s)}
                </div>
              );
            })}
        </div>
      </div>

      {preview && (
        <PlanillaModal
          anio={anio}
          division={division}
          materiaId={materiaId}
          materiaNombre={materiaNombre}
          ciclo={ciclo}
          editable={false}
          onClose={() => setPreview(false)}
          onSaved={() => {}}
        />
      )}
    </div>
  );
}
