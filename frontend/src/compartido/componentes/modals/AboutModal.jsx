// ===============================
// FILE: src/components/AboutModal.jsx
// ===============================
import React, { useEffect, useRef, useState } from "react";
import { motion } from "framer-motion";
import { X, Users, Code, BookOpen, Mail, Heart, ExternalLink, GraduationCap, ChevronDown } from "lucide-react";
import useCierreAnimado from "../../lib/useCierreAnimado";
import { bloquearScroll } from "../../lib/lockScroll";
import "./AboutModal.css";

// Componente: AboutModal
// Proposito: Modal "Acerca de" que muestra informacion del sistema, equipo de desarrollo,
//            licencia GPL v3.0, contexto academico del proyecto y datos de contacto.
// Props:
//   open    - Booleano que controla la visibilidad del modal.
//   onClose - Funcion para cerrar el modal.

const AboutModal = ({ open, onClose }) => {
  const dialogRef = useRef(null);
  const [verAnteriores, setVerAnteriores] = useState(false);
  const { montado, cerrando } = useCierreAnimado(open);

  // Datos reales del equipo
  const equipoActual = [
    { name: "Lucas Ojeda", role: "Desarrollador", color: "about-member-blue" },
    { name: "Leonardo Orué", role: "Desarrollador", color: "about-member-purple" },
    { name: "Sabrina Sola", role: "Desarrollador", color: "about-member-green" },
    { name: "Alejandro Podestá", role: "Desarrollador", color: "about-member-orange" }
  ];

  const equipoAnterior = [
    { name: "Facundo Carignani", role: "Desarrollador", color: "about-member-blue" },
    { name: "Santiago Forno", role: "Desarrollador", color: "about-member-purple" },
    { name: "Tomas Angélico", role: "Desarrollador", color: "about-member-green" },
    { name: "Manuel Costoya", role: "Desarrollador", color: "about-member-orange" }
  ];

  // Información actualizada del sistema
  const systemInfo = [
    { label: "Versión", value: "v2.0", icon: Code },
    { label: "Fecha de creación", value: "15/05/2025", icon: Users },
    { label: "Licencia", value: "GPL v3.0", icon: ExternalLink },
    { label: "Contexto", value: "Proyecto Académico", icon: BookOpen }
  ];

  /* Cierra el modal al presionar la tecla Escape */
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => e.key === "Escape" && onClose?.();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  /* Bloquea el scroll del body mientras el modal esta abierto */
  useEffect(() => {
    if (!montado) return;
    return bloquearScroll();
  }, [montado]);

  return (
    <>
      {montado && (
        <div className={`about-backdrop sys-capa${cerrando ? " sys-cerrando" : ""}`}>
          <div
            ref={dialogRef}
            className={`about-dialog sys-dialogo${cerrando ? " sys-cerrando" : ""}`}
            role="dialog"
            aria-modal="true"
          >
            {/* Header - Cargar primero */}
            <motion.div 
              initial={{ opacity: 0, y: -10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.08, duration: 0.2 }}
              className="about-header"
            >
              <div className="about-header-left">
                <div className="about-header-icon">
                  <GraduationCap size={28} />
                </div>
                <div>
                  <h2 className="about-title">Acerca del Sistema</h2>
                  <p className="about-subtitle">Sistema de Gestión Escolar - Proyecto Académico</p>
                </div>
              </div>

              {/* Botón cerrar - IDÉNTICO al AccountModal */}
              <button
                onClick={onClose}
                aria-label="Cerrar"
                className="about-close"
              >
                <X size={20} />
              </button>
            </motion.div>

            {/* Contenido con scroll - Animación escalonada */}
            <motion.div 
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.12, duration: 0.16 }}
              className="about-scroll"
            >
              <div className="about-content">
                {/* Logo y título institucional */}
                <motion.div
                  initial={{ opacity: 0, y: 15 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.16, duration: 0.2 }}
                  className="about-logo-card"
                >
                  <img
                    src="/EscudoENRO.png"
                    alt="Logo Escuela de Educación Secundaria Técnica N°2"
                    className="about-logo-img"
                  />
                  <h3 className="about-school-name">Escuela de Educación Secundaria Técnica</h3>
                  <h4 className="about-school-sub">E.E.S.T N°2</h4>
                  <p className="about-school-caption">Sistema de Gestión Escolar</p>
                  <p className="about-school-career">Tecnicatura Superior en Análisis de Sistemas</p>
                  <div className="about-heart-line">
                    <Heart size={14} className="about-heart" />
                    <span>Proyecto Integrador - ISFT N° 38</span>
                  </div>
                </motion.div>

                {/* Información del sistema en grid */}
                <motion.div
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: 0.24 }}
                >
                  <h4 className="about-section-title">
                    <Code size={20} className="about-ic-blue" />
                    Información del Sistema
                  </h4>
                  <div className="about-sys-grid">
                    {systemInfo.map((item, index) => {
                      const Icon = item.icon;
                      return (
                        <motion.div
                          key={item.label}
                          initial={{ opacity: 0, y: 8 }}
                          animate={{ opacity: 1, y: 0 }}
                          transition={{ delay: 0.28 + index * 0.04, duration: 0.2 }}
                          className="about-sys-card"
                        >
                          <div className="about-sys-card-row">
                            <div className="about-sys-icon">
                              <Icon size={18} />
                            </div>
                            <div>
                              <div className="about-sys-label">{item.label}</div>
                              <div className="about-sys-value">{item.value}</div>
                            </div>
                          </div>
                        </motion.div>
                      );
                    })}
                  </div>
                </motion.div>

                {/* Equipo de desarrollo */}
                <motion.div
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: 0.32 }}
                >
                  <h4 className="about-section-title">
                    <Users size={20} className="about-ic-purple" />
                    Equipo de Desarrollo
                  </h4>
                  <div className="about-grid-2col">
                    {equipoActual.map((member, index) => (
                      <motion.div
                        key={member.name}
                        initial={{ opacity: 0, x: -5 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.36 + index * 0.05, duration: 0.2 }}
                        className="about-team-card group"
                      >
                        <div className="about-team-row">
                          <div className={`about-team-avatar ${member.color}`}>
                            {member.name.split(' ').map(n => n[0]).join('')}
                          </div>
                          <div className="about-team-info">
                            <div className="about-team-name">{member.name}</div>
                            <div className="about-team-role">{member.role}</div>
                          </div>
                        </div>
                      </motion.div>
                    ))}
                  </div>

                  <button
                    type="button"
                    className="about-team-toggle"
                    onClick={() => setVerAnteriores((abierto) => !abierto)}
                    aria-expanded={verAnteriores}
                    aria-controls="about-equipo-anterior"
                  >
                    <ChevronDown
                      size={16}
                      className={
                        verAnteriores
                          ? "about-team-toggle-icon about-team-toggle-icon-abierto"
                          : "about-team-toggle-icon"
                      }
                    />
                    <span>Equipo de la versión inicial</span>
                    <span className="about-team-toggle-cantidad">{equipoAnterior.length}</span>
                  </button>

                  {verAnteriores && (
                    <div id="about-equipo-anterior" className="about-team-anteriores">
                      <div className="about-grid-2col">
                        {equipoAnterior.map((member) => (
                          <div key={member.name} className="about-team-card about-team-card-anterior group">
                            <div className="about-team-row">
                              <div className={`about-team-avatar ${member.color}`}>
                                {member.name.split(' ').map(n => n[0]).join('')}
                              </div>
                              <div className="about-team-info">
                                <div className="about-team-name">{member.name}</div>
                                <div className="about-team-role">{member.role}</div>
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </motion.div>

                {/* Descripción del proyecto */}
                <motion.div
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.62, duration: 0.2 }}
                  className="about-desc-card"
                >
                  <h4 className="about-section-title">
                    <BookOpen size={20} className="about-ic-green" />
                    Sobre este Proyecto
                  </h4>
                  <p className="about-text">
                    Este <strong>Sistema de Gestión Escolar</strong> fue desarrollado para la
                    <strong> Escuela de Educación Secundaria Técnica N°2</strong> como trabajo integrador 
                    para las materias de <strong>Ingeniería de Software II</strong> y 
                    <strong> Prácticas Profesionalizantes III</strong> de la 
                    <strong> Tecnicatura Superior en Análisis de Sistemas</strong> del ISFT N° 38.
                  </p>
                  <p className="about-text">
                    El proyecto tiene como objetivo demostrar las competencias adquiridas durante la carrera,
                    aplicando conceptos de desarrollo de software, bases de datos, ingeniería de software
                    y gestión de proyectos en un entorno educativo real.
                  </p>
                </motion.div>

                {/* Materias involucradas */}
                <motion.div
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.66, duration: 0.2 }}
                  className="about-grid-2col"
                >
                  <div className="about-subject-card about-subject-green">
                    <h5 className="about-subject-title">
                      <GraduationCap size={18} className="about-ic-green" />
                      Ingeniería de Software II
                    </h5>
                    <p className="about-subject-text">
                      Aplicación de metodologías de desarrollo, patrones de diseño y mejores prácticas de ingeniería de software.
                    </p>
                  </div>
                  <div className="about-subject-card about-subject-purple">
                    <h5 className="about-subject-title">
                      <Users size={18} className="about-ic-purple" />
                      Prácticas Profesionalizantes III
                    </h5>
                    <p className="about-subject-text">
                      Desarrollo de proyecto real integrando conocimientos técnicos y competencias profesionales.
                    </p>
                  </div>
                </motion.div>

                {/* Licencia */}
                <motion.div
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.70, duration: 0.2 }}
                  className="about-license-card"
                >
                  <h4 className="about-section-title">
                    <ExternalLink size={20} className="about-ic-green" />
                    Licencia GPL v3.0
                  </h4>
                  <p className="about-text">
                    Este software es libre: puedes redistribuirlo y/o modificarlo bajo los términos 
                    de la <strong>GNU General Public License</strong> publicada por la 
                    Free Software Foundation. Garantiza las cuatro libertades esenciales:
                  </p>
                  <ul className="about-license-list">
                    <li className="about-license-item">
                      <div className="about-license-dot" />
                      Usar el programa para cualquier propósito
                    </li>
                    <li className="about-license-item">
                      <div className="about-license-dot" />
                      Estudiar cómo funciona y adaptarlo a tus necesidades
                    </li>
                    <li className="about-license-item">
                      <div className="about-license-dot" />
                      Redistribuir copias para ayudar a otros
                    </li>
                    <li className="about-license-item">
                      <div className="about-license-dot" />
                      Mejorar el programa y publicar tus mejoras
                    </li>
                  </ul>
                </motion.div>

                {/* Contacto */}
                <motion.div
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: 0.74, duration: 0.18 }}
                  className="about-contact"
                >
                  <div className="about-contact-info">
                    <span>ↄ  {new Date().getFullYear()} ISFT N° 38</span>
                    <span>•</span>
                    <span>Tecnicatura en Análisis de Sistemas</span>
                  </div>
                  <div className="about-contact-email">
                    <Mail size={16} />
                    <a href="mailto:analisis.sistemas@isft38.edu.ar" className="about-contact-link">
                      isft38sannicolas@abc.gob.ar
                    </a>
                  </div>
                </motion.div>
              </div>
            </motion.div>
          </div>
        </div>
      )}
    </>
  );
};

export default AboutModal;