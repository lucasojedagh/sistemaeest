import { Sparkles } from "lucide-react";
import "../../styles/BotonNovedades.css";

// Boton "Hay novedades": avisa que otra persona toco los datos de esta
// pantalla. Mismo criterio que el boton del historial, para que sea familiar.
export default function BotonNovedades({ visible, onClick, desactivado }) {
  if (!visible) return null;
  return (
    <button type="button" onClick={onClick} disabled={desactivado} className="btn-novedades">
      <Sparkles size={15} />
      Hay novedades
    </button>
  );
}