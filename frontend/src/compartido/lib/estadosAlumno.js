// Estados de alumno que lo sacan de la cursada pero lo dejan en la base.
// Espejo de App\Dominios\Alumnos\Alumno::ESTADOS_BAJA del backend.
// El egresado terminó la escuela; el inactivo la abandonó: para el sistema
// son lo mismo (no cursan), la diferencia es la etiqueta y el motivo.
export const ESTADOS_ALUMNO = ['Activo', 'Egresado', 'Inactivo'];

export const ESTADOS_BAJA_ALUMNO = ['Egresado', 'Inactivo'];

// El estado llega como lo guardó el backend (o como lo escribió alguien a mano).
// Se normaliza para poder comparar; una fila sin estado cursa.
export const estadoNormalizado = (alumno) => {
  const v = String(alumno?.estado ?? '').trim().toLowerCase();
  return ESTADOS_ALUMNO.find((e) => e.toLowerCase() === v) || 'Activo';
};

export const estaFueraDeCursada = (alumno) =>
  ESTADOS_BAJA_ALUMNO.includes(estadoNormalizado(alumno));

// Un alumno fuera de cursada no tiene horario activo: muestra su último curso.
export const esCursadaCerrada = (alumno) =>
  estaFueraDeCursada(alumno) || !!alumno?.egreso_year;
