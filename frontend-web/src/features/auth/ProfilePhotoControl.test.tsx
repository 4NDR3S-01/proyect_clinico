import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from './authContext'
import { ProfilePhotoControl } from './ProfilePhotoControl'
import { deleteProfilePhoto, getProfilePhoto, uploadProfilePhoto } from './profilePhotoService'

vi.mock('./profilePhotoService', () => ({ getProfilePhoto: vi.fn(), uploadProfilePhoto: vi.fn(), deleteProfilePhoto: vi.fn() }))
const user = { id: 'user-id', name: 'Doctora', username: 'doctora', role: 'doctora' as const, paciente_id: null, activo: true, foto_perfil_url: '/api/usuarios/user-id/foto' }
const renderControl = (photo: string | null = user.foto_perfil_url) => render(<AuthContext.Provider value={{ status: 'authenticated', user: { ...user, foto_perfil_url: photo }, sessionError: null, login: async () => {}, logout: async () => {} }}><ProfilePhotoControl/></AuthContext.Provider>)

beforeEach(() => {
  vi.resetAllMocks()
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:protected-photo') })
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
})
afterEach(() => { vi.restoreAllMocks() })

describe('ProfilePhotoControl', () => {
  it('uses a protected Blob URL and releases it on unmount', async () => {
    vi.mocked(getProfilePhoto).mockResolvedValue(new Blob(['photo'], { type: 'image/png' }))
    const view = renderControl()
    await waitFor(() => expect(document.querySelector('img')).toHaveAttribute('src', 'blob:protected-photo'))
    expect(getProfilePhoto).toHaveBeenCalledExactlyOnceWith(user.id)
    view.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:protected-photo')
  })

  it('uses initials without a photo and validates the file before upload', async () => {
    renderControl(null)
    const trigger = screen.getByRole('button', { name: 'Gestionar foto de perfil' })
    expect(trigger).toHaveTextContent('D')
    fireEvent.click(trigger)
    const input = screen.getByLabelText('Seleccionar foto')
    fireEvent.change(input, { target: { files: [new File(['bad'], 'bad.txt', { type: 'text/plain' })] } })
    expect(screen.getByRole('alert')).toHaveTextContent('Selecciona una imagen JPG')
    expect(uploadProfilePhoto).not.toHaveBeenCalled()
  })

  it('uploads a valid image through the current-user service', async () => {
    vi.mocked(uploadProfilePhoto).mockResolvedValue(user.foto_perfil_url)
    vi.mocked(getProfilePhoto).mockResolvedValue(new Blob(['photo'], { type: 'image/png' }))
    renderControl(null)
    fireEvent.click(screen.getByRole('button', { name: 'Gestionar foto de perfil' }))
    const file = new File(['photo'], 'perfil.png', { type: 'image/png' })
    fireEvent.change(screen.getByLabelText('Seleccionar foto'), { target: { files: [file] } })
    await waitFor(() => expect(uploadProfilePhoto).toHaveBeenCalledExactlyOnceWith(file))
    await waitFor(() => expect(getProfilePhoto).toHaveBeenCalledExactlyOnceWith(user.id))
  })

  it('uploads only the current user photo and allows deleting it', async () => {
    vi.mocked(getProfilePhoto).mockResolvedValue(new Blob(['photo'], { type: 'image/png' }))
    vi.mocked(uploadProfilePhoto).mockResolvedValue(user.foto_perfil_url)
    vi.mocked(deleteProfilePhoto).mockResolvedValue(null)
    renderControl()
    fireEvent.click(screen.getByRole('button', { name: 'Gestionar foto de perfil' }))
    fireEvent.click(screen.getByRole('button', { name: 'Eliminar foto' }))
    await waitFor(() => expect(deleteProfilePhoto).toHaveBeenCalledTimes(1))
    expect(screen.getByRole('button', { name: 'Gestionar foto de perfil' })).toHaveTextContent('D')
  })
})
