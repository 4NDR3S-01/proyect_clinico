import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ProtectedPhoto } from './ProtectedPhoto'
import { getProfilePhoto } from './profilePhotoService'

vi.mock('./profilePhotoService', () => ({ getProfilePhoto: vi.fn() }))

beforeEach(() => {
  vi.resetAllMocks()
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:protected-photo') })
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
})
afterEach(() => vi.restoreAllMocks())

describe('ProtectedPhoto', () => {
  it('uses the user ID from the backend photo route and releases the temporary Blob URL', async () => {
    vi.mocked(getProfilePhoto).mockResolvedValue(new Blob(['photo'], { type: 'image/webp' }))
    const view = render(<ProtectedPhoto name="Paciente recibido" photoUrl="https://backend.test/api/usuarios/user-42/foto"/>)
    await waitFor(() => expect(document.querySelector('img')).toHaveAttribute('src', 'blob:protected-photo'))
    expect(getProfilePhoto).toHaveBeenCalledExactlyOnceWith('user-42', expect.any(AbortSignal))
    expect(URL.createObjectURL).toHaveBeenCalledOnce()
    view.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:protected-photo')
  })

  it('shows initials without requesting a photo when Laravel supplies no URL', () => {
    render(<ProtectedPhoto name="Paciente recibido" photoUrl={null}/>)
    expect(screen.getByText('P')).toBeInTheDocument()
    expect(getProfilePhoto).not.toHaveBeenCalled()
  })

  it('shares a protected download across avatars and a return visit', async () => {
    vi.mocked(getProfilePhoto).mockResolvedValue(new Blob(['photo'], { type: 'image/webp' }))
    const first = render(<><ProtectedPhoto name="Uno" photoUrl="/api/usuarios/user-42/foto"/><ProtectedPhoto name="Dos" photoUrl="/api/usuarios/user-42/foto"/></>)
    await waitFor(() => expect(document.querySelectorAll('img')).toHaveLength(2))
    expect(getProfilePhoto).toHaveBeenCalledTimes(1)
    first.unmount()
    const returned = render(<ProtectedPhoto name="Uno" photoUrl="/api/usuarios/user-42/foto"/>)
    await waitFor(() => expect(document.querySelector('img')).toBeInTheDocument())
    expect(getProfilePhoto).toHaveBeenCalledTimes(1)
    returned.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(3)
  })
})
