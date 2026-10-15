import { BarChart3, CalendarClock, CalendarDays, ChevronDown, ClipboardList, LayoutDashboard, LogOut, Menu, Settings, UserRound, UsersRound, Wallet, X, type LucideIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../features/auth/authContext'
import type { UserRole } from '../features/auth/authService'
import { canAccessArea, type RestrictedArea } from '../features/auth/roleAccess'
import { ProtectedPhoto } from '../features/auth/ProtectedPhoto'

type NavItem = { to: string; label: string; icon: LucideIcon; access?: RestrictedArea }

const navItems: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/agenda', label: 'Agenda', icon: CalendarDays, access: 'agenda' },
  { to: '/citas-medicas', label: 'Citas médicas', icon: CalendarClock, access: 'agenda' },
  { to: '/actividades', label: 'Actividades', icon: ClipboardList, access: 'actividades' },
  { to: '/caja', label: 'Caja', icon: Wallet, access: 'caja' },
  { to: '/pacientes', label: 'Pacientes', icon: UsersRound, access: 'pacientes' },
  { to: '/reportes', label: 'Reportes', icon: BarChart3, access: 'reportes' },
  { to: '/usuarios', label: 'Usuarios', icon: UserRound, access: 'usuarios' },
  { to: '/configuracion', label: 'Configuración', icon: Settings, access: 'configuracion' },
]

const roleLabels: Record<UserRole, string> = { doctora: 'Doctora', asistente: 'Asistente', paciente: 'Paciente' }

function Sidebar({ close }: { close?: () => void }) {
  const { user } = useAuth()
  const visibleItems = navItems.filter(({ access }) => !access || canAccessArea(user?.role, access))

  return (
    <div className="admin-sidebar flex h-full min-h-0 flex-col overflow-y-auto px-4 py-7">
      <div className="flex shrink-0 items-center gap-3 border-b border-sky-200/15 px-2 pb-7">
        <span aria-hidden="true" className="admin-brand-mark grid size-11 shrink-0 place-items-center rounded-[14px]"><svg viewBox="0 0 64 72" fill="none" className="h-7 w-6"><path d="M32 10C24 10 19 5 12 8 4 11 5 23 8 33c3 11 8 30 13 31 5 1 6-18 11-18s6 19 11 18c5-1 10-20 13-31 3-10 4-22-4-25-7-3-12 2-20 2Z" stroke="currentColor" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round"/></svg></span>
        <div className="min-w-0">
          <p className="truncate text-[15px] font-bold tracking-tight text-white">Clínica Dental</p>
          <p className="mt-0.5 text-[11px] tracking-wide text-sky-200/80">Historial clínico odontológico</p>
        </div>
        {close ? (
          <button aria-label="Cerrar menú" autoFocus className="admin-interactive ml-auto grid size-11 shrink-0 place-items-center rounded-lg text-sky-100 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white" onClick={close} type="button">
            <X aria-hidden="true"/>
          </button>
        ) : null}
      </div>

      <p aria-hidden="true" className="mb-3 mt-8 px-3 text-[10px] font-bold uppercase tracking-[0.2em] text-sky-200/65">Espacio de trabajo</p>
      <nav aria-label="Navegación principal" className="space-y-1">
        {visibleItems.map(({ to, label, icon: Icon }) => (
          <NavLink
            className={({ isActive }) => `admin-nav-link flex min-h-11 items-center gap-3 rounded-[10px] px-3 text-[13px] font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white ${isActive ? 'font-semibold' : 'text-sky-100/75 hover:bg-white/8 hover:text-white'}`}
            end={to === '/'}
            key={to}
            onClick={close}
            to={to}
          >
            <Icon aria-hidden="true" size={18} strokeWidth={1.7}/>{label}
          </NavLink>
        ))}
      </nav>

    </div>
  )
}

export function AppLayout() {
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const [logoutBusy, setLogoutBusy] = useState(false)
  const [photoUrl, setPhotoUrl] = useState(user?.foto_perfil_url ?? null)
  const [photoRevision, setPhotoRevision] = useState(0)
  const logoutPending = useRef(false)
  const profileTrigger = useRef<HTMLButtonElement>(null)
  const profileMenu = useRef<HTMLDivElement>(null)
  const menuTriggerRef = useRef<HTMLButtonElement>(null)
  const drawerRef = useRef<HTMLDivElement>(null)
  const location = useLocation()
  const title = location.pathname === '/mi-perfil' ? 'Mi perfil' : location.pathname === '/servicios' ? 'Servicios del consultorio' : navItems.find((item) => item.to === '/' ? location.pathname === '/' : location.pathname.startsWith(item.to))?.label ?? 'Expediente clínico'

  useEffect(() => {
    const onPhoto = (event: Event) => { setPhotoUrl((event as CustomEvent<string | null>).detail); setPhotoRevision((value) => value + 1) }
    window.addEventListener('clinic:profile-photo-changed', onPhoto)
    return () => window.removeEventListener('clinic:profile-photo-changed', onPhoto)
  }, [])

  useEffect(() => {
    if (!profileOpen) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { setProfileOpen(false); profileTrigger.current?.focus(); return }
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return
      const items = [...(profileMenu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])]
      if (!items.length || !(profileMenu.current?.contains(document.activeElement) || document.activeElement === profileTrigger.current)) return
      event.preventDefault()
      const current = items.indexOf(document.activeElement as HTMLElement)
      const next = event.key === 'ArrowDown' ? (current + 1) % items.length : (current < 0 ? items.length - 1 : (current - 1 + items.length) % items.length)
      items[next].focus()
    }
    const onPointerDown = (event: PointerEvent) => {
      if (!(event.target instanceof Node) || profileMenu.current?.contains(event.target) || profileTrigger.current?.contains(event.target)) return
      setProfileOpen(false)
    }
    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    return () => { document.removeEventListener('keydown', onKeyDown); document.removeEventListener('pointerdown', onPointerDown) }
  }, [profileOpen])

  const signOut = async () => {
    if (logoutPending.current) return
    logoutPending.current = true
    setLogoutBusy(true)
    try { await logout() } finally { logoutPending.current = false; setLogoutBusy(false); setProfileOpen(false) }
  }

  const closeMenu = () => {
    setOpen(false)
    menuTriggerRef.current?.focus()
  }

  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        menuTriggerRef.current?.focus()
      }
      if (event.key === 'Tab' && drawerRef.current?.getClientRects().length) {
        const controls = [...drawerRef.current.querySelectorAll<HTMLElement>('a[href], button:not(:disabled)')]
          .filter((control) => control.tabIndex >= 0)
        const first = controls[0]
        const last = controls[controls.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last?.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first?.focus()
        }
      }
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [open])

  return (
    <div className={`admin-shell min-h-screen lg:grid lg:grid-cols-[272px_1fr] ${location.pathname === '/' ? 'dashboard-shell' : ''}`}>
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-[272px] lg:block"><Sidebar/></aside>
      {open ? (
        <div aria-label="Menú de navegación" aria-modal="true" className="fixed inset-0 z-40 lg:hidden" ref={drawerRef} role="dialog">
          <button aria-label="Cerrar navegación" className="admin-drawer-backdrop absolute inset-0 bg-[#04112c]/65" onClick={closeMenu} tabIndex={-1} type="button"/>
          <aside className="admin-drawer-panel relative h-full w-[286px] max-w-[85vw] shadow-2xl"><Sidebar close={closeMenu}/></aside>
        </div>
      ) : null}
      <div className="min-w-0 lg:col-start-2">
        <header className="admin-header sticky top-0 z-20 flex min-h-20 items-center px-4 sm:px-7 lg:px-10">
          <button aria-label="Abrir menú" aria-expanded={open} className="admin-interactive mr-3 grid size-11 shrink-0 place-items-center rounded-lg text-[#315a87] hover:bg-sky-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-clinic-600 lg:hidden" onClick={() => setOpen(true)} ref={menuTriggerRef} type="button">
            <Menu aria-hidden="true"/>
          </button>
          <div className="min-w-0">
            <p className="hidden text-[10px] font-bold uppercase tracking-[0.18em] text-[#647f9f] sm:block">Clínica Dental / Portal clínico</p>
            <p className="truncate text-sm font-bold tracking-tight text-ink-950 sm:mt-1 sm:text-[17px]">{title}</p>
          </div>
          <div className="ml-auto flex shrink-0 items-center gap-3 pl-3">
            <span aria-hidden="true" className="h-7 w-px bg-[#d5e3f0]"/>
            {user ? <div className="relative">
              <button aria-controls="profile-menu" aria-expanded={profileOpen} aria-haspopup="menu" aria-label="Abrir menú de perfil" className="admin-interactive flex min-h-11 items-center gap-2 rounded-lg px-1 text-slate-600 hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-clinic-600" onClick={() => setProfileOpen((value) => !value)} onKeyDown={(event) => { if (event.key === 'ArrowDown' && !profileOpen) { event.preventDefault(); setProfileOpen(true); requestAnimationFrame(() => profileMenu.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()) } }} ref={profileTrigger} type="button">
                <ProtectedPhoto className="size-10 rounded-full text-sm font-semibold" key={`${user.id}-${photoRevision}`} name={user.name} photoUrl={photoUrl}/>
                <span className="hidden min-w-0 flex-col text-left text-sm leading-tight sm:flex"><span className="max-w-[12rem] truncate font-medium text-ink-950" title={user.name}>{user.name}</span><span className="text-xs text-slate-500">{roleLabels[user.role]}</span></span><ChevronDown aria-hidden="true" size={16}/>
              </button>
              {profileOpen ? <div className="admin-surface-raised absolute right-0 top-full z-30 mt-2 w-[min(19rem,calc(100vw-2rem))] rounded-xl border border-[#dbe6f2] p-2 shadow-lg" id="profile-menu" ref={profileMenu} role="menu">
                <div className="flex min-w-0 items-center gap-3 border-b border-[#e6edf6] px-3 py-3"><ProtectedPhoto className="size-11 rounded-full text-sm font-semibold" name={user.name} photoUrl={photoUrl}/><div className="min-w-0"><p className="truncate text-sm font-semibold text-ink-950">{user.name}</p><p className="text-xs text-slate-500">{roleLabels[user.role]}</p></div></div>
                <Link className="admin-interactive mt-1 flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-950 hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-clinic-600" onClick={() => setProfileOpen(false)} role="menuitem" to="/mi-perfil"><UserRound aria-hidden="true" size={17}/>Mi perfil</Link>
                <button className="admin-interactive flex min-h-11 w-full items-center gap-2 rounded-lg px-3 text-left text-sm font-medium text-ink-950 hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-clinic-600 disabled:opacity-60" disabled={logoutBusy} onClick={() => { void signOut() }} role="menuitem" type="button"><LogOut aria-hidden="true" size={17}/>{logoutBusy ? 'Cerrando sesión…' : 'Cerrar sesión'}</button>
              </div> : null}
            </div> : null}
          </div>
        </header>
        <main className="mx-auto w-full max-w-[1536px] p-4 sm:p-7 lg:px-10 lg:py-9"><Outlet context={photoUrl}/></main>
      </div>
    </div>
  )
}
