// ===============================
// FILE: src/components/Navbar.jsx
// ===============================
import React, { useState, useMemo, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Home, Users, BookOpen, Calendar, User, ChevronRight, Info, Shield, Clock, UserCog, FileText, CalendarCheck, Menu, X } from 'lucide-react';
import { useAuth } from '../../../modulos/auth/AuthContext';
import AccountModal from '../modals/AccountModal';
import AboutModal from '../modals/AboutModal';
import './Navbar.css';

// Componente: Navbar
// Proposito: Barra de navegacion lateral (sidebar) con menu principal, acceso a gestion
//            de usuarios (admin/directivo/jefe_preceptores), boton de cuenta, y modales
//            de informacion. Filtra las opciones de menu segun el rol del usuario.
// Props: ninguna (usa useAuth y useLocation internamente).

const getInitials = (fullName = 'U') =>
  fullName
    .split(' ')
    .filter(Boolean)
    .map(p => p[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);

const Navbar = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [accountOpen, setAccountOpen] = useState(false);
  const [aboutOpen, setAboutOpen] = useState(false);
  // Drawer en celular: el sidebar se desliza desde la izquierda.
  const [movilAbierto, setMovilAbierto] = useState(false);

  const canManage = user?.role === 'admin' || user?.role === 'directivo' || user?.role === 'jefe_preceptores';

  /* Navega y cierra el drawer en celular */
  const irA = (path) => {
    navigate(path);
    setMovilAbierto(false);
  };

  /* Navega a la pagina de administracion de usuarios */
  const openUsersPage = () => {
    irA('/admin/usuarios');
  };

  /* Por seguridad el drawer siempre se cierra al cambiar de página */
  useEffect(() => {
    setMovilAbierto(false);
  }, [location.pathname]);

  /* Menu base con todas las opciones disponibles (sin restricciones de rol) */
  const baseMenu = useMemo(() => ([
    { id: 'dashboard',   label: 'Inicio',         icon: Home,    path: '/dashboard',    enabled: true, seccion: 'principal' },
    { id: 'alumnos',     label: 'Alumnos',        icon: Users,   path: '/alumnos',      enabled: true, seccion: 'principal' },
    { id: 'cursos',      label: 'Cursos',         icon: BookOpen,path: '/cursos',       enabled: true, seccion: 'principal' },
    { id: 'asistencia',  label: 'Asistencia',     icon: CalendarCheck, path: '/asistencia', enabled: true, seccion: 'principal' },
    { id: 'calificaciones', label: 'Calificaciones', icon: Calendar, path: '/calificaciones', enabled: true, seccion: 'principal' },
    { id: 'documentos',  label: 'Documentos',  icon: FileText, path: '/documentos', enabled: true, seccion: 'principal' },
    { id: 'profesores',  label: 'Profesores',     icon: User,    path: '/profesores',   enabled: true, seccion: 'gestion' },
    { id: 'preceptores', label: 'Preceptores',    icon: UserCog, path: '/preceptores',  enabled: true, seccion: 'gestion' },
    { id: 'jefe-preceptores', label: 'Jefes de preceptores', icon: Shield, path: '/jefe-preceptores',  enabled: true, seccion: 'gestion' },
  ]), []);

  /* Filtra las opciones del menu segun el rol del usuario (solo frontend) */
  const menuItems = useMemo(() => {
    if (user?.role === 'profesor') {
      return baseMenu.filter(i => !['preceptores', 'jefe-preceptores'].includes(i.id));
    }
    if (user?.role === 'preceptor' || user?.role === 'prosecretaria') {
      return baseMenu.filter(i => !['jefe-preceptores'].includes(i.id));
    }
    if (['admin', 'jefe_preceptores'].includes(user?.role)) {
      return baseMenu;
    }
    if (user?.role === 'directivo') {
      return baseMenu.filter(i => i.id !== 'jefe-preceptores');
    }
    return baseMenu.filter(i => !['preceptores', 'jefe-preceptores'].includes(i.id));
  }, [baseMenu, user?.role]);

  /* Bloquea el acceso por URL directa a rutas restringidas segun el rol */
  useEffect(() => {
    const restrictedByRole = {
      profesor: ['/preceptores', '/jefe-preceptores'],
      preceptor: ['/jefe-preceptores'],
      prosecretaria: ['/jefe-preceptores'],
    };
    const restricted = restrictedByRole[user?.role] || [];
    if (restricted.some(p => location.pathname === p || location.pathname.startsWith(p + '/'))) {
      navigate('/dashboard', { replace: true });
    }
  }, [user?.role, location.pathname, navigate]);

  const displayName = user?.name || 'Usuario';
  const displayEmail = user?.email || '—';
  const initials = getInitials(displayName);

  const isActivePath = (p) => {
    if (p === '/dashboard') return location.pathname === '/' || location.pathname.startsWith('/dashboard');
    return location.pathname === p || location.pathname.startsWith(p + '/');
  };

  const menuPrincipal = menuItems.filter((i) => i.seccion !== 'gestion');
  const menuGestion = menuItems.filter((i) => i.seccion === 'gestion');

  const renderItems = (items) => items.map((item) => {
    const Icon = item.icon;
    const active = isActivePath(item.path);
    return (
      <li key={item.id}>
        <button
          disabled={!item.enabled}
          onClick={() => item.enabled && irA(item.path)}
          className={`
            menu-item
            ${active && item.enabled ? 'menu-item-active' : ''}
            ${!item.enabled ? 'menu-item-disabled' : ''}
            ${item.enabled && !active ? 'menu-item-hoverable' : ''}
          `}
        >
          <Icon size={20} />
          <span className="menu-item-label">{item.label}</span>
        </button>
      </li>
    );
  });

  return (
    <>
      {/* Botón hamburguesa (solo celular) */}
      <button
        className="sidebar-hamburguesa"
        onClick={() => setMovilAbierto(true)}
        aria-label="Abrir menú"
      >
        <Menu size={22} />
      </button>
      {movilAbierto && (
        <div
          className="sidebar-backdrop"
          onClick={() => setMovilAbierto(false)}
          aria-hidden="true"
        />
      )}

      {/* Sidebar principal: logo, buscador, menu por secciones y pie */}
      <div className={`sidebar${movilAbierto ? " sidebar--abierto" : ""}`}>
        {/* Logo institucional y nombre de la escuela */}
        <div className="sidebar-logo">
          <img
            src="/EscudoENRO.png"
            alt="Logo Escuela"
            className="sidebar-logo-img"
          />
          <h1 className="sidebar-title">E.E.S.T N° 2</h1>
          <button
            className="sidebar-cerrar"
            onClick={() => setMovilAbierto(false)}
            aria-label="Cerrar menú"
          >
            <X size={18} />
          </button>
        </div>

        {/* Menu por secciones segun el rol del usuario */}
        <nav className="sidebar-nav" aria-label="Módulos del sistema">
          <div className="sidebar-seccion">Principal</div>
          <ul className="sidebar-menu">
            {renderItems(menuPrincipal)}
          </ul>
          {menuGestion.length > 0 && (
            <>
              <div className="sidebar-seccion">Personal</div>
              <ul className="sidebar-menu">
                {renderItems(menuGestion)}
              </ul>
            </>
          )}
        </nav>

        {/* Seccion inferior: botones de gestion de usuarios, historial, documentos, y cuenta */}
        <div className="sidebar-footer-actions">
          {/* Botones de administracion solo visibles para admin, directivo y jefe de preceptores */}
          {canManage && (
            <>
              <button
                onClick={() => irA('/historial')}
                className="action-btn"
                aria-label="Historial de actividad"
              >
                <div className="action-btn-avatar action-btn-avatar-amber">
                  <Clock size={18} />
                </div>
                <div className="action-btn-text">
                  <div className="action-btn-title">Historial</div>
                  <div className="action-btn-subtitle">Registro de actividad</div>
                </div>
                <ChevronRight size={18} className="action-btn-chevron" />
              </button>
              <button
                onClick={openUsersPage}
                className="action-btn"
                aria-label="Gestionar usuarios"
              >
                <div className="action-btn-avatar action-btn-avatar-blue">
                  <Shield size={18} />
                </div>
                <div className="action-btn-text">
                  <div className="action-btn-title">Usuarios</div>
                  <div className="action-btn-subtitle">Administrar cuentas</div>
                </div>
                <ChevronRight size={18} className="action-btn-chevron" />
              </button>
            </>
          )}

          {/* Boton de cuenta del usuario: abre el modal AccountModal */}
          <button
            onClick={() => setAccountOpen(true)}
            className="action-btn"
            aria-label="Abrir cuenta"
          >
            <div className="action-btn-avatar action-btn-avatar-blue">
              {initials}
            </div>
            <div className="action-btn-text">
              <div className="action-btn-title action-btn-title-truncate">{displayName}</div>
              <div className="action-btn-subtitle action-btn-subtitle-truncate">{displayEmail}</div>
            </div>
            <ChevronRight size={18} className="action-btn-chevron" />
          </button>
        </div>

        {/* Pie de la barra: informacion del sistema, copyleft y enlace "Acerca de" */}
        <div className="sidebar-footer">
          <div className="sidebar-footer-copy">Sistema de Gestión Educativa</div>
          <div className="sidebar-footer-year">ↄ {new Date().getFullYear()} E.E.S.T N°2</div>
          <button
            onClick={() => setAboutOpen(true)}
            className="sidebar-footer-about"
          >
            <Info size={12} />
            Acerca de
          </button>
        </div>
      </div>

      {/* Modales: cuenta y acerca de */}
      <AccountModal open={accountOpen} onClose={() => setAccountOpen(false)} />
      <AboutModal open={aboutOpen} onClose={() => setAboutOpen(false)} />
    </>
  );
};

export default Navbar;
