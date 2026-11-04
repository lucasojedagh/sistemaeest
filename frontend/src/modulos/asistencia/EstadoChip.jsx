import { ESTADO_LABELS } from "../../compartido/lib/asistencia";
import "./EstadoChip.css";

const ESTADO_CHIP_CLASSES = {
  presente: "estado-chip estado-chip-presente",
  ausente: "estado-chip estado-chip-ausente",
  justificado: "estado-chip estado-chip-justificado",
  tarde: "estado-chip estado-chip-tarde",
};

export default function EstadoChip({ estado }) {
  return (
    <span className={ESTADO_CHIP_CLASSES[estado] ?? "estado-chip estado-chip-default"}>
      {ESTADO_LABELS[estado] ?? estado}
    </span>
  );
}