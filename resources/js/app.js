import { Input } from 'postcss';
import './bootstrap';
import axios from 'axios';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

axios.defaults.headers.common['X-CSRF-TOKEN'] =
    document.querySelector('meta[name="csrf-token"]').content;

let Senha = document.getElementById("password");
let Senha2 = document.getElementById("password-conf");

if (Senha) {
    Senha.addEventListener('input', function(){
        this.type = 'text';
        clearTimeout(this._timer);

        this._timer = setTimeout(() =>{
            this.type = 'password';
        }, 2000);
    });

    Senha.addEventListener('blur', function(){
        this.type = 'password';
    });
}

if (Senha2) {
    Senha2.addEventListener('input', function(){
        this.type = 'text';
        clearTimeout(this._timer);

        this._timer = setTimeout(() =>{
            this.type = 'password';
        }, 2000);
    });

    Senha2.addEventListener('blur', function(){
        this.type = 'password';
    });
}

document.querySelectorAll('input[type="tel"], input[name="phone"]').forEach((input) => {
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', '15');

    input.addEventListener('input', function (e) {
        let v = e.target.value.replace(/\D/g, '');

        if (v.length > 11) {
            v = v.slice(0, 11);
        }

        if (v.length > 10) {
            e.target.value = v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
        } else if (v.length > 6) {
            e.target.value = v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
        } else if (v.length > 2) {
            e.target.value = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
        } else if (v.length > 0) {
            e.target.value = v.replace(/(\d{0,2})/, '($1');
        } else {
            e.target.value = '';
        }
    });
});
